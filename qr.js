import {api, button, el, money, orderCard} from './ordering.js';

const byId = id => document.getElementById(id);
const token = new URLSearchParams(location.search).get('table') ?? '';
const basket = new Map();
const products = new Map();
let table;
let accepting = false;
let stale = false;
let sending = false;
let requestKey;
let retryOnly = false;
let canRefreshOrders = false;

function updateBasket(focusId) {
  const lines = byId('basket-lines');
  lines.replaceChildren();
  for (const [id, item] of basket) {
    const row = el('div', undefined, 'row basket-line');
    const label = el('label', item.name + (item.available ? ' · ' + money(item.price) : ' · Нет в наличии'), 'grow');
    label.htmlFor = 'quantity-' + id;
    const quantity = el('input');
    Object.assign(quantity, {id: label.htmlFor, type: 'number', min: 1, max: 20, value: item.quantity, disabled: sending || retryOnly});
    quantity.addEventListener('change', () => {
      item.quantity = Math.max(1, Math.min(20, Math.floor(Number(quantity.value)) || 1));
      updateBasket(quantity.id);
    });
    const remove = button('Убрать', () => {
      basket.delete(id);
      updateBasket();
    }, 'secondary');
    remove.disabled = sending || retryOnly;
    row.append(label, quantity, remove);
    lines.append(row);
  }
  const count = [...basket.values()].reduce((sum, item) => sum + item.quantity, 0);
  const total = [...basket.values()].reduce((sum, item) => sum + (item.available ? item.price * item.quantity : 0), 0);
  byId('basket-link').hidden = byId('basket').hidden = !count;
  byId('basket-link').textContent = 'Корзина · ' + count;
  byId('total').textContent = 'Итого ' + money(total);
  byId('send').textContent = retryOnly ? 'Проверить отправку' : 'Отправить заказ';
  byId('send').disabled = sending || !table || stale || (!retryOnly && (!accepting || [...basket.values()].some(item => !item.available)));
  for (const add of document.querySelectorAll('[data-add]')) {
    const item = products.get(add.dataset.add);
    add.disabled = !table || sending || retryOnly || stale || !accepting || !item.available;
  }
  if (focusId) byId(focusId)?.focus();
}

function addLine(id) {
  const product = products.get(id);
  const item = basket.get(id) ?? {...product, quantity: 0};
  if (item.quantity < 20 && (basket.has(id) || basket.size < 30)) {
    item.quantity += 1;
    basket.set(id, item);
    byId('feedback').textContent = '';
    updateBasket();
  }
}

function renderMenu(categories) {
  products.clear();
  byId('categories').replaceChildren();
  byId('menu').replaceChildren();
  for (const category of categories) {
    if (!category.items.length) continue;
    const section = el('section', undefined, 'menu-group');
    section.id = 'category-' + category.id;
    const link = el('a', category.name);
    link.href = '#' + section.id;
    byId('categories').append(link);
    const list = el('div', undefined, 'stack');
    for (const dish of category.items) {
      const card = el('article', undefined, 'dish card');
      if (dish.image) {
        const image = el('img');
        image.src = dish.image.startsWith('photo.php?id=') ? dish.image : 'assets/' + dish.image + '-204.webp';
        image.alt = '';
        image.loading = 'lazy';
        card.append(image);
      }
      const info = el('div', undefined, 'grow');
      info.append(el('h3', dish.name));
      if (dish.description) info.append(el('p', dish.description));
      if (!dish.variants.length) info.append(el('p', 'Нет в наличии', 'status'));
      for (const variant of dish.variants) {
        const id = String(variant.id);
        const available = Boolean(dish.available && variant.available);
        const name = dish.name + (variant.label ? ' · ' + variant.label : '');
        products.set(id, {id: Number(id), name, price: Number(variant.price), available});
        const row = el('div', undefined, 'option');
        row.append(el('span', [variant.label, money(variant.price)].filter(Boolean).join(' · ')));
        if (available) {
          const add = button('Добавить', () => addLine(id));
          add.dataset.add = id;
          add.setAttribute('aria-label', 'Добавить ' + name);
          row.append(add);
        } else {
          row.append(el('span', 'Нет в наличии', 'status'));
        }
        info.append(row);
      }
      card.append(info);
      list.append(card);
    }
    section.append(el('h2', category.name), list);
    byId('menu').append(section);
  }
  updateBasket();
}

async function refreshOrders() {
  const orders = await api('my_orders');
  byId('orders').hidden = !orders.length;
  byId('order-list').replaceChildren(...orders.map(order => orderCard(order)));
}

function setNotice() {
  if (!table || stale) return;
  byId('notice').textContent = accepting ? '' : 'Приём заказов приостановлен. Обратитесь к официанту.';
}

byId('send').addEventListener('click', async () => {
  sending = true;
  requestKey ??= crypto.randomUUID();
  updateBasket();
  const feedback = byId('feedback');
  feedback.textContent = 'Отправляем заказ…';
  let sent = false;
  try {
    await api('order', {
      key: requestKey, token, generation: table.generation,
      lines: [...basket.values()].map(({id, quantity, price}) => ({id, quantity, price})),
    });
    sent = true;
    retryOnly = false;
    requestKey = undefined;
    basket.clear();
    await refreshOrders();
    byId('orders').scrollIntoView();
  } catch (error) {
    if (sent) {
      byId('notice').textContent = 'Заказ принят системой. Статус появится в разделе «Мои заказы».';
    } else {
      retryOnly = !error.status || error.status >= 500;
      feedback.textContent = retryOnly ? 'Связь прервалась. Нажмите «Проверить отправку», чтобы продолжить.' : error.message;
      stale = Boolean(error.stale) || error.status === 404;
      if (error.expired) {
        await api('session').catch(() => {});
      }
      if (Array.isArray(error.changes)) {
        for (const change of error.changes) {
          const item = basket.get(String(change.id));
          if (!item) continue;
          item.available = change.available;
          if (change.available) item.price = Number(change.price);
        }
        api('catalog').then(renderMenu).catch(() => {});
      }
    }
  } finally {
    sending = false;
    updateBasket();
  }
});

async function init() {
  const catalog = api('catalog');
  const context = api('table', undefined, {token});
  const [menu, result] = await Promise.allSettled([catalog, context]);
  canRefreshOrders = true;
  refreshOrders().catch(() => {});
  if (result.status === 'fulfilled') {
    ({table, accepting} = result.value);
    byId('table').textContent = 'Столик ' + table.label;
    setNotice();
  } else {
    byId('notice').textContent = result.reason.message;
  }
  if (menu.status === 'fulfilled') renderMenu(menu.value);
  else byId('notice').textContent = 'Меню не загрузилось. Обновите страницу.';
}

init();
setInterval(async () => {
  if (document.hidden || !canRefreshOrders) return;
  await Promise.allSettled([
    refreshOrders(),
    api('accepting').then(result => {
      accepting = result.accepting;
      setNotice();
      byId('send').disabled = sending || stale || (!retryOnly && (!accepting || [...basket.values()].some(item => !item.available)));
      for (const add of document.querySelectorAll('[data-add]')) {
        add.disabled = sending || retryOnly || stale || !accepting || !products.get(add.dataset.add).available;
      }
    }),
  ]);
}, 7000);
