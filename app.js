const $ = (selector) => document.querySelector(selector);
const formatter = new Intl.NumberFormat('ru-KZ');

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text) node.textContent = text;
  return node;
}

async function readData(path) {
  const response = await fetch(path);
  if (!response.ok) throw new Error(path + ': ' + response.status);
  return response.json();
}

function dishImage(dish, sizes) {
  const image = element('img');
  const path = 'assets/' + dish.image;
  image.src = path + '-204.webp';
  image.srcset = path + '-144.webp 144w, ' + path + '-204.webp 204w';
  image.sizes = sizes;
  image.width = 204;
  image.height = 116;
  image.loading = 'lazy';
  image.decoding = 'async';
  image.alt = 'Подача блюда «' + dish.name + '»';
  return image;
}

function price(dish, placeholder) {
  const label = element('span', 'price');
  if (!dish.prices.length) label.textContent = placeholder;
  dish.prices.forEach((amount, index) => {
    if (index) label.append(' / ');
    label.append(element('span', '', formatter.format(amount) + '\u00a0₸'));
  });
  return label;
}

function renderFeatured(categories, restaurant) {
  const dishes = categories.flatMap(category => category.items);
  const names = restaurant.popularDishes.length ? restaurant.popularDishes : restaurant.featuredDishes;
  if (restaurant.popularDishes.length) $('#featured-title').textContent = 'Популярные блюда';
  for (const name of names) {
    const dish = dishes.find(item => item.name === name);
    if (!dish) continue;
    const article = element('article', 'featured-dish');
    if (dish.image) article.append(dishImage(dish, '(min-width: 1000px) 300px, (min-width: 700px) 22vw, 45vw'));
    const caption = element('div');
    caption.append(element('h3', '', dish.name), price(dish, restaurant.placeholder));
    article.append(caption);
    $('#featured').append(article);
  }
}

function renderMenu(categories, restaurant) {
  const tabList = $('.tabs');
  const panels = $('#menu-panels');
  const tabs = [];
  const sections = [];
  for (const [index, category] of categories.entries()) {
    const tab = element('button', 'tab', category.name);
    tab.type = 'button';
    tab.id = 'tab-' + index;
    tab.setAttribute('role', 'tab');
    tab.setAttribute('aria-controls', 'panel-' + index);
    tab.setAttribute('aria-selected', String(index === 0));
    tab.tabIndex = index === 0 ? 0 : -1;

    const panel = element('div');
    panel.id = 'panel-' + index;
    panel.setAttribute('role', 'tabpanel');
    panel.setAttribute('aria-labelledby', tab.id);
    panel.tabIndex = 0;
    panel.hidden = index !== 0;
    const list = element('ul', 'menu-list');
    for (const dish of category.items) {
      const row = element('li', 'menu-row');
      if (dish.image) row.append(dishImage(dish, '64px'));
      const info = element('div');
      info.append(element('h3', '', dish.name || restaurant.placeholder));
      if (dish.description) info.append(element('p', '', dish.description));
      row.append(info, price(dish, restaurant.placeholder));
      list.append(row);
    }
    panel.append(list);
    tabs.push(tab);
    sections.push(panel);
    tabList.append(tab);
    panels.append(panel);
  }

  let selected = 0;
  function select(index) {
    tabs[selected].setAttribute('aria-selected', 'false');
    tabs[selected].tabIndex = -1;
    sections[selected].hidden = true;
    selected = index;
    const tab = tabs[index];
    tab.setAttribute('aria-selected', 'true');
    tab.tabIndex = 0;
    sections[index].hidden = false;
    tab.focus({preventScroll: true});
    tab.scrollIntoView({block: 'nearest', inline: 'nearest', behavior: 'instant'});
  }

  tabs.forEach((tab, index) => tab.addEventListener('click', () => select(index)));
  tabList.addEventListener('keydown', event => {
    const steps = {
      ArrowRight: (selected + 1) % tabs.length,
      ArrowLeft: (selected - 1 + tabs.length) % tabs.length,
      Home: 0,
      End: tabs.length - 1,
    };
    if (!Object.hasOwn(steps, event.key)) return;
    event.preventDefault();
    select(steps[event.key]);
  });
  for (const [selector, direction] of [['.prev', -1], ['.next', 1]]) {
    $(selector).addEventListener('click', () => {
      const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
      tabList.scrollBy({left: direction * tabList.clientWidth * 0.82, behavior: reducedMotion ? 'instant' : 'smooth'});
    });
  }
  $('.tab-bar').hidden = false;
}

async function loadRestaurant() {
  try {
    const [restaurant, categories] = await Promise.all([readData('restaurant.json'), readData('menu.json')]);
    document.querySelectorAll('[data-value]').forEach(node => {
      node.textContent = restaurant[node.dataset.value] || restaurant.placeholder;
    });
    if (restaurant.phone) $('[data-value="phone"]').href = 'tel:' + restaurant.phone.replace(/[^+\d]/g, '');
    if (restaurant.whatsapp) {
      const number = restaurant.whatsapp.replace(/\D/g, '');
      const message = encodeURIComponent(restaurant.whatsappMessage);
      document.querySelectorAll('[data-whatsapp]').forEach(link => {
        link.href = 'https://wa.me/' + number + '?text=' + message;
        link.hidden = false;
      });
    }
    $('#instagram').href = restaurant.instagram;
    renderFeatured(categories, restaurant);
    renderMenu(categories, restaurant);
  } catch (error) {
    $('#menu-status').textContent = 'Меню не загрузилось. Обновите страницу.';
    console.error(error);
  }
}

const navToggle = $('#nav-toggle');
const mobileNav = $('#mobile-nav');
function setNav(open) {
  navToggle.setAttribute('aria-expanded', String(open));
  mobileNav.hidden = !open;
}
navToggle.addEventListener('click', () => setNav(mobileNav.hidden));
mobileNav.addEventListener('click', event => {
  const link = event.target.closest('a');
  if (!link) return;
  setNav(false);
  if (!link.hash) return;
  const target = $(link.hash);
  target.tabIndex = -1;
  target.focus({preventScroll: true});
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && !mobileNav.hidden) {
    setNav(false);
    navToggle.focus();
  }
});
matchMedia('(min-width: 1000px)').addEventListener('change', () => setNav(false));

const dialog = $('#photo-dialog');
const dialogImage = $('#photo-dialog img');
document.querySelectorAll('.gallery-item').forEach(button => {
  button.addEventListener('click', () => {
    const image = button.querySelector('img');
    dialogImage.width = image.width;
    dialogImage.height = image.height;
    dialogImage.alt = image.alt;
    dialogImage.src = button.dataset.photo;
    dialog.showModal();
  });
});
$('.photo-close').addEventListener('click', () => dialog.close());
dialog.addEventListener('click', event => {
  if (event.target === dialog) dialog.close();
});

loadRestaurant();
