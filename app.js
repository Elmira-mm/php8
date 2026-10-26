// Практична робота №5 — AJAX-каталог
// Весь обмін даними з сервером іде через fetch() у форматі JSON,
// сторінка жодного разу не перезавантажується.

let allProducts = []; // локальний кеш списку товарів, отриманий через api_list.php

document.addEventListener('DOMContentLoaded', () => {
    loadProducts();

    document.getElementById('search-input').addEventListener('input', onSearchInput);
    document.getElementById('add-form').addEventListener('submit', onAddSubmit);
});

/**
 * Екранування перед вставкою в innerHTML — захист від XSS.
 * Екранує & < > " ' — отже безпечно і в тексті, і у значеннях атрибутів у лапках.
 */
function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatMoney(value) {
    const num = Number(value);
    return num.toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * Початкове завантаження каталогу: GET-запит до api_list.php.
 */
async function loadProducts() {
    const tbody = document.getElementById('catalog-body');
    tbody.innerHTML = '<tr><td colspan="5" class="empty-row">Завантаження…</td></tr>';

    try {
        const response = await fetch('api_list.php');
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Невідома помилка сервера.');
        }

        allProducts = data.products;
        renderTotal(data.total);
        renderProducts(allProducts);
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="5" class="empty-row">Помилка завантаження: ${escapeHtml(err.message)}</td></tr>`;
    }
}

/**
 * Жива фільтрація за назвою товару — подія 'input', без запиту на сервер
 * і без перезавантаження сторінки: фільтруємо вже завантажений масив.
 */
function onSearchInput(event) {
    const term = event.target.value.trim().toLowerCase();

    const filtered = term === ''
        ? allProducts
        : allProducts.filter(p => p.name.toLowerCase().includes(term));

    renderProducts(filtered);
}

function renderProducts(products) {
    const tbody = document.getElementById('catalog-body');

    if (products.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="empty-row">Нічого не знайдено.</td></tr>';
        return;
    }

    tbody.innerHTML = products.map(rowTemplate).join('');
}

function rowTemplate(p) {
    const inStock = Number(p.stock) > 0;

    return `
        <tr class="${inStock ? 'row-in' : 'row-out'}" data-id="${escapeHtml(p.id)}">
            <td><span class="product-name">${escapeHtml(p.name)}</span></td>
            <td class="stock">${escapeHtml(p.sku)}</td>
            <td><span class="price">${formatMoney(p.price)}</span><span class="price-currency">грн</span></td>
            <td class="stock">${escapeHtml(p.stock)}</td>
            <td>
                <span class="badge ${inStock ? 'badge-in' : 'badge-out'}">
                    ${inStock ? 'В наявності' : 'Немає в наявності'}
                </span>
            </td>
        </tr>
    `;
}

function renderTotal(total) {
    const el = document.getElementById('total-value');
    if (el) {
        el.textContent = `${formatMoney(total)} грн`;
    }
}

/**
 * Додавання товару: POST-запит до api_add.php через fetch.
 * Новий рядок додається в DOM одразу з відповіді сервера — без reload.
 */
async function onAddSubmit(event) {
    event.preventDefault();

    const form = event.target;
    clearFormErrors(form);

    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    submitBtn.disabled = true;

    try {
        const response = await fetch('api_add.php', {
            method: 'POST',
            body: formData,
        });
        const data = await response.json();

        if (!data.success) {
            // помилки валідації по полях, або загальне повідомлення (наприклад, 403 від CSRF-перевірки)
            showFormErrors(form, data.errors || { name: data.error || 'Не вдалося зберегти товар.' });
            return;
        }

        allProducts.push(data.product);
        renderTotal(data.total);

        // якщо активний пошуковий фільтр не відповідає новому товару,
        // все одно оновлюємо поточний перелічений (відфільтрований) вигляд
        onSearchInput({ target: document.getElementById('search-input') });

        form.reset();
        showAddSuccess();
    } catch (err) {
        showFormErrors(form, { name: 'Не вдалося зв\'язатися із сервером.' });
    } finally {
        submitBtn.disabled = false;
    }
}

function clearFormErrors(form) {
    form.querySelectorAll('.error').forEach(el => (el.textContent = ''));
}

function showFormErrors(form, errors) {
    Object.entries(errors).forEach(([field, message]) => {
        const el = form.querySelector(`[data-error-for="${field}"]`);
        if (el) {
            el.textContent = message;
        }
    });
}

function showAddSuccess() {
    const alertEl = document.getElementById('add-success');
    if (!alertEl) return;
    alertEl.style.display = 'flex';
    window.clearTimeout(showAddSuccess._t);
    showAddSuccess._t = window.setTimeout(() => {
        alertEl.style.display = 'none';
    }, 2500);
}
