import {api, button, el, orderCard} from './ordering.js';

const byId = id => document.getElementById(id);
const content = byId('content');
const message = byId('message');
const tabs = [...document.querySelectorAll('[data-tab]')];
let user;
let tab = 'queue';
let accepting;
let offset = 0;
let revision = 0;
let busy = false;
let detailId = '';

function feedback(text, error = false) {
  message.textContent = text;
  message.classList.toggle('error', error);
}

function updateAcceptance(value) {
  accepting = value;
  byId('acceptance').textContent = value ? 'Приём заказов включён' : 'Приём заказов выключен';
  byId('switch').textContent = value ? 'Остановить приём' : 'Включить приём';
  byId('switch').disabled = false;
}

async function mutate(action, input, control, refresh = true) {
  if (busy) return;
  busy = true;
  if (control) control.disabled = true;
  feedback('');
  try {
    const result = await api(action, input);
    if (result.link) {
      const link = el('a', result.link);
      link.href = result.link;
      message.append('Одноразовая ссылка на 30 минут. Передайте сотруднику лично. ', link);
      return;
    }
    feedback('Сохранено.');
    if (refresh) await loadTab();
  } catch (error) {
    feedback(error.message, true);
    if (error.status === 409 && tab === 'queue') await loadTab().catch(() => {});
    if (error.status === 401) await boot().catch(() => {});
  } finally {
    if (control) control.disabled = false;
    busy = false;
  }
}

function actionButton(text, action, input, className, confirmText) {
  return button(text, event => {
    if (!confirmText || confirm(confirmText)) mutate(action, input, event.currentTarget);
  }, className);
}

function renderQueue(data) {
  updateAcceptance(data.accepting);
  content.replaceChildren();
  if (!data.visits.length) content.append(el('p', 'Открытых столиков нет.'));
  for (const visit of data.visits) {
    const section = el('section', undefined, 'panel');
    section.append(el('h2', 'Столик ' + visit.label));
    const orders = el('div', undefined, 'stack');
    const sorted = [...visit.orders].sort((a, b) => Number(b.status === 'pending') - Number(a.status === 'pending'));
    for (const order of sorted) {
      const payload = {id: order.id, version: order.version};
      const card = orderCard(order, (item, left, line) => {
        if (order.status !== 'cooking' || !left) return;
        const wrapper = el('div', undefined, 'row');
        const quantity = el('input');
        Object.assign(quantity, {type: 'number', min: 1, max: left, value: 1});
        quantity.setAttribute('aria-label', 'Сколько порций отменить: ' + line.name);
        wrapper.append(quantity, button('Отменить порции', event => {
          if (quantity.reportValidity()) {
            mutate('cancel_line', {...payload, line: line.id, quantity: Number(quantity.value)}, event.currentTarget);
          }
        }, 'secondary'));
        item.append(wrapper);
      });
      const actions = el('div', undefined, 'row staff-actions');
      switch (order.status) {
        case 'pending':
          actions.append(actionButton('Принять', 'accept', payload),
            actionButton('Отклонить', 'reject', payload, 'danger', 'Отклонить заказ №' + order.id + '?'),
            actionButton('Спам', 'spam', payload, 'secondary', 'Отметить заказ как спам?'));
          break;
        case 'cooking':
          actions.append(actionButton('Подан', 'serve', payload));
          break;
        case 'served':
          actions.append(actionButton('Вернуть в «Готовится»', 'undo_serve', payload, 'secondary'));
          break;
      }
      if (actions.childElementCount) card.append(actions);
      orders.append(card);
    }
    const close = actionButton('Закрыть столик', 'close', {id: visit.id}, 'secondary', 'Закрыть столик ' + visit.label + '?');
    close.disabled = visit.orders.some(order => ['pending', 'cooking'].includes(order.status));
    const footer = el('div', undefined, 'staff-actions');
    footer.append(close);
    if (close.disabled) footer.append(el('p', 'Перед закрытием завершите все заказы.', 'option'));
    section.append(orders, footer);
    content.append(section);
  }
}

function field(name, label, value = '', type = 'text', choices = []) {
  const wrapper = el('label', label);
  const input = el(type === 'select' ? 'select' : type === 'textarea' ? 'textarea' : 'input');
  input.name = name;
  if (type === 'select') {
    for (const [key, caption] of choices) {
      const option = el('option', caption);
      option.value = key;
      input.append(option);
    }
  } else if (type !== 'textarea') {
    input.type = type;
  }
  if (type === 'checkbox') input.checked = Boolean(value);
  else if (type !== 'file') input.value = value ?? '';
  if (type === 'number') input.min = 0;
  if (type === 'password') {
    input.required = true;
    input.minLength = 10;
    input.autocomplete = 'new-password';
  }
  if (type === 'file') input.accept = 'image/jpeg,image/png,image/webp';
  wrapper.append(input);
  if (type === 'password') {
    wrapper.append(button('Показать пароль', event => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      event.currentTarget.textContent = show ? 'Скрыть пароль' : 'Показать пароль';
    }, 'secondary'));
  }
  return wrapper;
}

function editor(action, id, fields, caption = 'Сохранить') {
  const form = el('form', undefined, 'panel');
  const grid = el('div', undefined, 'fields');
  grid.append(...fields);
  const submit = el('button', caption);
  submit.type = 'submit';
  form.append(grid, submit);
  form.addEventListener('submit', event => {
    event.preventDefault();
    const values = new FormData(form);
    values.set('id', id);
    for (const checkbox of form.querySelectorAll('[type="checkbox"]')) values.set(checkbox.name, checkbox.checked ? '1' : '0');
    mutate('admin_' + action, values, submit);
  });
  return form;
}

function sectionDetails(id, title, children) {
  const details = el('details', undefined, 'card');
  details.open = id === detailId;
  details.append(el('summary', title), ...children);
  details.querySelector('summary').addEventListener('click', () => {
    detailId = details.open ? '' : id;
    if (!details.open) {
      for (const other of content.querySelectorAll('details[open]')) other.open = false;
    }
  });
  return details;
}

function categoryForm(category = {}) {
  return editor('category', category.id ?? 0, [
    field('name', 'Название', category.name), field('sort_order', 'Порядок', category.sort_order ?? 0, 'number'),
    field('published', 'Показывать в меню', category.published ?? true, 'checkbox'),
  ], category.id ? 'Сохранить категорию' : 'Добавить категорию');
}

function dishForm(categories, dish = {}) {
  const choices = categories.map(category => [category.id, category.name]);
  return editor('dish', dish.id ?? 0, [
    field('category_id', 'Категория', dish.category_id ?? choices[0]?.[0], 'select', choices),
    field('name', 'Название', dish.name), field('description', 'Описание', dish.description, 'textarea'),
    field('sort_order', 'Порядок', dish.sort_order ?? 0, 'number'), field('photo', 'Фото до 6 МБ', '', 'file'),
    field('published', 'Показывать в меню', dish.published ?? true, 'checkbox'),
    field('available', 'Блюдо в наличии', dish.available ?? true, 'checkbox'),
    ...(dish.image ? [field('remove_photo', 'Убрать текущее фото', false, 'checkbox')] : []),
  ], dish.id ? 'Сохранить блюдо' : 'Добавить блюдо');
}

function variantForm(dishId, variant = {}) {
  const form = editor('variant', variant.id ?? 0, [
    field('label', 'Вариант порции', variant.label), field('price', 'Цена, ₸', variant.price, 'number'),
    field('available', 'Вариант в наличии', variant.available ?? true, 'checkbox'),
  ], variant.id ? 'Сохранить цену' : 'Добавить вариант');
  const dish = el('input');
  Object.assign(dish, {type: 'hidden', name: 'dish_id', value: dishId});
  form.append(dish);
  return form;
}

function renderCatalog(categories) {
  content.replaceChildren(sectionDetails('new-category', 'Добавить категорию', [categoryForm()]));
  if (categories.length) content.append(sectionDetails('new-dish', 'Добавить блюдо', [dishForm(categories)]));
  for (const category of categories) {
    content.append(el('h2', category.name));
    content.append(sectionDetails('category-' + category.id, 'Настройки категории', [categoryForm(category)]));
    for (const dish of category.items) {
      const children = [dishForm(categories, dish), el('h3', 'Цены и порции')];
      children.push(...dish.variants.map(variant => variantForm(dish.id, variant)), variantForm(dish.id));
      content.append(sectionDetails('dish-' + dish.id, dish.name + (!dish.published ? ' · скрыто' : !dish.available ? ' · нет в наличии' : ''), children));
    }
  }
}

async function downloadQr(url, name) {
  try {
    const {default: qrcode} = await import('./vendor/qrcode.js');
    const qr = qrcode(0, 'M');
    qr.addData(url);
    qr.make();
    const file = URL.createObjectURL(new Blob([qr.createSvgTag({cellSize: 8, margin: 32, scalable: true,
      title: 'REMI · ' + name})], {type: 'image/svg+xml'}));
    const link = el('a');
    link.href = file;
    link.download = 'remi-stolik-' + name.replace(/[<>:"/\\|?*]/g, '-') + '.svg';
    link.click();
    setTimeout(() => URL.revokeObjectURL(file), 1000);
  } catch {
    feedback('Не удалось скачать QR.', true);
  }
}

function renderTables(data) {
  content.replaceChildren(editor('table', 0, [field('label', 'Название или номер столика')], 'Добавить столик'));
  for (const table of data.tables) {
    const url = new URL('qr.html', (data.base || location.origin) + '/');
    url.searchParams.set('table', table.qr_token);
    const link = el('a', 'Открыть меню столика');
    link.href = url.href;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    const actions = el('div', undefined, 'row');
    actions.append(link, button('Скачать QR', () => downloadQr(url.href, table.label)),
      actionButton('Заменить QR', 'admin_rotate', {id: table.id}, 'danger', 'Старый QR перестанет принимать заказы. Заменить код?'));
    content.append(sectionDetails('table-' + table.id, 'Столик ' + table.label, [
      editor('table', table.id, [field('label', 'Название или номер', table.label)]), actions,
    ]));
  }
}

function renderStaff(staff) {
  content.replaceChildren(editor('staff_add', 0, [
    field('login', 'Короткий логин'), field('password', 'Пароль', '', 'password'),
    field('role', 'Роль', 'waiter', 'select', [['waiter', 'Официант'], ['admin', 'Администратор']]),
  ], 'Добавить сотрудника'));
  for (const person of staff) {
    const actions = el('div', undefined, 'row');
    if (person.enabled) {
      actions.append(actionButton('Ссылка для Telegram', 'admin_staff_link', {id: person.id}, 'secondary'));
      if (person.telegram_id) actions.append(actionButton('Отвязать Telegram', 'admin_staff_unlink', {id: person.id},
        'secondary', 'Сотрудник потеряет доступ к действиям через бота. Отвязать Telegram?'));
      if (person.id !== user.id) actions.append(actionButton('Отключить доступ', 'admin_staff_disable', {id: person.id},
        'danger', 'Отключить вход на сайт и доступ к боту?'));
    } else {
      actions.append(actionButton('Включить доступ', 'admin_staff_enable', {id: person.id}));
    }
    const label = person.role === 'admin' ? 'администратор' : 'официант';
    content.append(sectionDetails('staff-' + person.id, person.login + ' · ' + label + (!person.enabled ? ' · отключён' : ''), [
      el('p', person.telegram_id ? 'Telegram привязан' : 'Telegram не привязан'),
      editor('staff_password', person.id, [field('password', 'Новый пароль', '', 'password')], 'Установить пароль'),
      actions,
    ]));
  }
}

async function loadTab() {
  const request = ++revision;
  if (tab === 'queue') {
    const data = await api('queue');
    if (request === revision) renderQueue(data);
  } else if (tab === 'history') {
    const orders = await api('history', undefined, {offset});
    if (request !== revision) return;
    content.replaceChildren(...orders.map(order => orderCard(order)));
    if (!orders.length) content.append(el('p', 'Завершённых заказов нет.'));
    const pagination = el('div', undefined, 'row');
    const step = (text, delta) => button(text, () => {
      offset += delta;
      loadTab().catch(error => feedback(error.message, true));
    }, 'secondary');
    if (offset) pagination.append(step('Предыдущие', -100));
    if (orders.length === 100) pagination.append(step('Следующие', 100));
    content.append(pagination);
  } else {
    const data = await api('admin');
    if (request !== revision) return;
    if (tab === 'catalog') renderCatalog(data.catalog);
    if (tab === 'tables') renderTables(data);
    if (tab === 'staff') renderStaff(data.staff);
  }
}

async function openTab(next) {
  tab = next;
  offset = 0;
  detailId = '';
  for (const item of tabs) {
    if (item.dataset.tab === tab) item.setAttribute('aria-current', 'page');
    else item.removeAttribute('aria-current');
  }
  content.replaceChildren(el('p', 'Загрузка…'));
  await loadTab();
}

async function boot() {
  ({staff: user} = await api('session'));
  byId('login').hidden = Boolean(user);
  byId('workspace').hidden = !user;
  byId('identity').textContent = user ? user.login : 'Персонал';
  document.querySelectorAll('.admin').forEach(item => { item.hidden = user?.role !== 'admin'; });
  if (user) await openTab('queue');
}

byId('login').addEventListener('submit', async event => {
  event.preventDefault();
  const form = event.currentTarget;
  const submit = form.querySelector('button');
  submit.disabled = true;
  try {
    await api('login', Object.fromEntries(new FormData(form)));
    form.reset();
    feedback('');
    await boot();
  } catch (error) {
    feedback(error.message, true);
  } finally {
    submit.disabled = false;
  }
});
byId('switch').addEventListener('click', async event => {
  await mutate('accepting', {enabled: Number(!accepting)}, event.currentTarget, false);
  api('accepting').then(result => updateAcceptance(result.accepting)).catch(error => feedback(error.message, true));
});
byId('logout').addEventListener('click', async () => {
  try {
    await api('logout', {});
    content.replaceChildren();
    feedback('');
    await boot();
  } catch (error) {
    feedback(error.message, true);
  }
});
for (const item of tabs) item.addEventListener('click', () => {
  feedback('');
  openTab(item.dataset.tab).catch(error => feedback(error.message, true));
});

boot().catch(error => feedback(error.message, true));
setInterval(() => {
  if (!user || busy || document.hidden) return;
  const task = tab === 'queue' && !content.contains(document.activeElement)
    ? loadTab() : api('accepting').then(result => updateAcceptance(result.accepting));
  task.catch(error => feedback(error.message, true));
}, 6000);
