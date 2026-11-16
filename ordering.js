export const status = {
  pending: 'Ожидает подтверждения', cooking: 'Готовится', served: 'Подан',
  rejected: 'Заказ отклонён', cancelled: 'Заказ отменён',
};

export const money = amount => new Intl.NumberFormat('ru-KZ').format(amount) + ' ₸';
const orderTime = new Intl.DateTimeFormat('ru-KZ', {
  dateStyle: 'short', timeStyle: 'short', timeZone: 'Asia/Almaty',
});

export function el(tag, text, className) {
  const node = document.createElement(tag);
  if (text !== undefined) node.textContent = text;
  if (className) node.className = className;
  return node;
}

export function button(text, handler, className) {
  const node = el('button', text, className);
  node.type = 'button';
  node.addEventListener('click', handler);
  return node;
}

let csrf;
export async function api(action, input, params = {}) {
  const query = new URLSearchParams({action, ...params});
  const multipart = input instanceof FormData;
  const response = await fetch('api.php?' + query, input === undefined ? {} : {
    method: 'POST',
    headers: {'X-CSRF-Token': csrf ?? '', ...(multipart ? {} : {'Content-Type': 'application/json'})},
    body: multipart ? input : JSON.stringify(input),
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw Object.assign(new Error(data.error || 'Сервис временно недоступен.'), data, {status: response.status});
  if (data.csrf) csrf = data.csrf;
  return data;
}

export function orderCard(order, controls) {
  const card = el('article', undefined, 'card');
  card.append(el('h3', 'Заказ №' + order.id + (order.label ? ' · ' + order.label : '')),
    el('p', status[order.status], 'status'));
  if (order.created_at) {
    const timestamp = order.created_at.replace(' ', 'T') + 'Z';
    const time = el('time', orderTime.format(new Date(timestamp)), 'option');
    time.dateTime = timestamp;
    card.append(time);
  }
  const list = el('ul', undefined, 'order-lines');
  for (const line of order.lines) {
    const left = line.quantity - line.cancelled;
    const item = el('li', line.name + ' × ' + left + (line.cancelled ? ' · отменено: ' + line.cancelled : ''));
    controls?.(item, left, line);
    list.append(item);
  }
  card.append(list, el('strong', 'Итого ' + money(order.total)));
  return card;
}
