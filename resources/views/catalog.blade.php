@extends('layouts.app')

@section('content')
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 transition-colors duration-300 d-flex">
        <div class="container mx-auto px-4 py-8">
            <!-- Заголовок -->
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100 mb-6 text-center md:text-left">
                Каталог товарів
            </h1>

            <div class="flex flex-col lg:flex-row gap-6">
                <!-- Фільтри -->
                <aside class="w-full lg:w-1/4 bg-white dark:bg-gray-800 rounded-xl shadow-lg p-4 sticky top-4">
                    <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100 mb-4">Фільтри</h2>
                    <div id="filter-container" class="space-y-4">
                        <div id="filter-grid" class="grid grid-cols-1 md:grid-cols-2 gap-2">
                            <div id="category-filter" class="mb-2"></div>
                            <div id="brand-filter" class="mb-2"></div>
                        </div>
                        <div id="other-filters" class="space-y-2"></div>
                        <!-- Кнопка "Додому" -->
                        <button id="reset-filters" class="w-full bg-gray-600 dark:bg-gray-500 text-white py-2 rounded-lg hover:bg-gray-700 dark:hover:bg-gray-600 transition-colors">
                            Скинути фільтри
                        </button>
                    </div>
                </aside>

                <!-- Товари -->
                <main class="w-full lg:w-3/4">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100">Товари</h2>
                            <div class="flex items-center gap-2">
                                <label for="sort" class="text-gray-600 dark:text-gray-300">Сортування:</label>
                                <select id="sort" class="border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100 rounded-lg p-2 focus:ring-2 focus:ring-blue-500">
                                    <option value="price-asc">Ціна: за зростанням</option>
                                    <option value="price-desc">Ціна: за спаданням</option>
                                </select>
                            </div>
                        </div>

                        <div id="product-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6"></div>

                        <div id="pagination-container" class="mt-8 flex justify-center items-center gap-4"></div>
                    </div>
                </main>
            </div>
        </div>
    </div>

    <script>
        // Стан
        let state = {
            filters: {},
            sort: 'price',
            order: 'asc',
            page: 1,
            limit: 10
        };

        // Завантаження при старті
        document.addEventListener('DOMContentLoaded', () => {
            loadFilters();
            loadProducts();
            const sortSelect = document.getElementById('sort');
            if (sortSelect) {
                sortSelect.addEventListener('change', (e) => {
                    const [sort, order] = e.target.value.split('-');
                    state.sort = sort;
                    state.order = order;
                    state.page = 1;
                    loadProducts();
                });
            }
            // Додаємо обробник для кнопки "Додому"
            const resetButton = document.getElementById('reset-filters');
            if (resetButton) {
                resetButton.addEventListener('click', () => {
                    resetFilters();
                    state.page = 1;
                    loadFilters();
                    loadProducts();
                });
            }
        });

        // Скидання фільтрів
        function resetFilters() {
            state.filters = {}; // Очищаємо всі фільтри
        }

        // Завантаження фільтрів
        async function loadFilters() {
            try {
                const filterParams = buildFilterParams();
                const url = `/api/catalog/filters?paramSlug=all${filterParams ? `&${filterParams}` : ''}`;
                console.log('Fetching filters:', url);
                const response = await fetch(url, {
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                const data = await response.json();
                console.log('Filters API response:', data);
                if (!Array.isArray(data)) {
                    throw new Error('Очікується масив фільтрів у відповіді');
                }
                renderFilters(data);
            } catch (error) {
                console.error('Помилка завантаження фільтрів:', error);
                document.getElementById('filter-container').innerHTML = '<p class="text-red-500 dark:text-red-400">Помилка завантаження фільтрів</p>';
            }
        }

        // Завантаження продуктів
        async function loadProducts() {
            try {
                const filterParams = buildFilterParams();
                const params = new URLSearchParams(filterParams);
                params.append('sort', state.sort);
                params.append('order', state.order);
                params.append('page', state.page);
                params.append('limit', state.limit);
                const url = `/api/catalog/products?${params}`;
                console.log('Fetching products:', url);
                const response = await fetch(url, {
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                const data = await response.json();
                console.log('Products API response:', data);
                if (!data.data || !data.meta) {
                    throw new Error('Продукти або мета відсутні у відповіді');
                }
                renderProducts(data.data, data.meta);
            } catch (error) {
                console.error('Помилка завантаження продуктів:', error);
                document.getElementById('product-container').innerHTML = '<p class="text-red-500 dark:text-red-400">Помилка завантаження товарів</p>';
            }
        }

        // Формування параметрів фільтрів
        function buildFilterParams() {
            const params = new URLSearchParams();
            for (const [key, values] of Object.entries(state.filters)) {
                values.forEach(value => params.append(`filter[${key}][]`, value));
            }
            return params.toString();
        }

        // Рендер фільтрів
        function renderFilters(filters) {
            const categoryContainer = document.getElementById('category-filter');
            const brandContainer = document.getElementById('brand-filter');
            const otherContainer = document.getElementById('other-filters');
            const filterGrid = document.getElementById('filter-grid');

            categoryContainer.innerHTML = '';
            brandContainer.innerHTML = '';
            otherContainer.innerHTML = '';

            // Оновлення класу сітки залежно від вибору категорії
            const hasCategory = state.filters.category && state.filters.category.length > 0;
            filterGrid.className = hasCategory
                ? 'grid grid-cols-1 gap-2'
                : 'grid grid-cols-1 md:grid-cols-2 gap-2';

            if (!filters || filters.length === 0) {
                categoryContainer.innerHTML = '<p class="text-gray-500 dark:text-gray-400 text-sm">Фільтри відсутні</p>';
                return;
            }

            filters.forEach(filter => {
                if (!filter || !filter.slug || !filter.name || !Array.isArray(filter.values)) {
                    console.warn('Invalid filter object:', filter);
                    return;
                }

                const group = document.createElement('div');
                group.className = 'mb-2';

                const title = document.createElement('h3');
                title.className = 'text-base font-semibold text-gray-700 dark:text-gray-200 mb-1';
                title.textContent = filter.name;
                group.appendChild(title);

                const content = document.createElement('div');
                content.className = 'space-y-1';

                filter.values.forEach(value => {
                    if (!value || typeof value !== 'object' || !value.value) {
                        console.warn('Invalid value in filter:', { filter: filter.slug, value });
                        return;
                    }

                    const div = document.createElement('div');
                    div.className = 'flex items-center';

                    if (filter.slug === 'category') {
                        const link = document.createElement('a');
                        link.href = '#';
                        link.className = `text-blue-600 dark:text-blue-400 hover:underline text-sm ${state.filters[filter.slug]?.includes(value.value) ? 'font-semibold' : ''}`;
                        link.textContent = `${value.name || value.value} (${value.count || 0})`;
                        link.addEventListener('click', (e) => {
                            e.preventDefault();
                            toggleFilterState(filter.slug, value.value);
                            state.page = 1;
                            loadFilters();
                            loadProducts();
                        });
                        div.appendChild(link);
                    } else if (filter.slug === 'brand' && hasCategory) {
                        const span = document.createElement('span');
                        span.className = `text-blue-600 dark:text-blue-400 text-sm cursor-pointer ${state.filters[filter.slug]?.includes(value.value) ? 'font-semibold' : ''}`;
                        span.textContent = `${value.name || value.value} (${value.count || 0})`;
                        span.addEventListener('click', () => {
                            toggleFilterState(filter.slug, value.value);
                            state.page = 1;
                            loadFilters();
                            loadProducts();
                        });
                        div.appendChild(span);
                    } else {
                        const label = document.createElement('label');
                        label.className = 'flex items-center text-sm text-gray-600 dark:text-gray-300 cursor-pointer';
                        const checkbox = document.createElement('input');
                        checkbox.type = 'checkbox';
                        checkbox.value = value.value;
                        checkbox.className = 'h-3.5 w-3.5 text-blue-600 dark:text-blue-400 border-gray-300 dark:border-gray-600 rounded focus:ring-blue-500 mr-1.5';
                        checkbox.checked = state.filters[filter.slug]?.includes(value.value) || false;

                        if (value.count === 0) {
                            checkbox.disabled = true;
                            label.classList.add('text-gray-400', 'dark:text-gray-500');
                            checkbox.classList.add('cursor-not-allowed');
                        }
                        checkbox.addEventListener('change', () => {
                            updateFilterState(filter.slug, value.value, checkbox.checked);
                            state.page = 1;
                            loadFilters();
                            loadProducts();
                        });
                        label.appendChild(checkbox);
                        label.appendChild(document.createTextNode(`${value.name || value.value} (${value.count || 0})`));
                        div.appendChild(label);
                    }

                    content.appendChild(div);
                });

                group.appendChild(content);

                if (filter.slug === 'category') {
                    categoryContainer.appendChild(group);
                } else if (filter.slug === 'brand') {
                    brandContainer.appendChild(group);
                } else {
                    otherContainer.appendChild(group);
                }
            });
        }

        // Перемикання стану для категорій і брендів
        function toggleFilterState(slug, value) {
            if (!state.filters[slug]) state.filters[slug] = [];
            if (state.filters[slug].includes(value)) {
                state.filters[slug] = state.filters[slug].filter(v => v !== value);
                if (state.filters[slug].length === 0) {
                    delete state.filters[slug];
                }
            } else {
                state.filters[slug] = [value];
            }
        }

        // Оновлення стану для чекбоксів
        function updateFilterState(slug, value, checked) {
            if (!state.filters[slug]) state.filters[slug] = [];
            if (checked) {
                if (!state.filters[slug].includes(value)) {
                    state.filters[slug].push(value);
                }
            } else {
                state.filters[slug] = state.filters[slug].filter(v => v !== value);
                if (state.filters[slug].length === 0) {
                    delete state.filters[slug];
                }
            }
        }

        // Рендер продуктів і пагінації
        function renderProducts(products, meta) {
            const container = document.getElementById('product-container');
            container.innerHTML = '';
            if (!products || products.length === 0) {
                container.innerHTML = '<p class="text-gray-500 dark:text-gray-400 text-center col-span-full">Товари не знайдено</p>';
                return;
            }

            products.forEach(product => {
                const card = document.createElement('div');
                card.className = 'bg-white dark:bg-gray-700 rounded-lg shadow-md overflow-hidden transform transition-transform duration-300 hover:scale-105 hover:shadow-lg';
                card.innerHTML = `
                    <div class="p-4">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">${product.name || 'Без назви'}</h3>
                        <p class="text-gray-600 dark:text-gray-300 mt-1">Ціна: ${product.price || 0} грн</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 line-clamp-2">${product.description || 'Без опису'}</p>
                        <button class="mt-4 w-full bg-blue-600 dark:bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors">
                            Додати до кошика
                        </button>
                    </div>
                `;
                container.appendChild(card);
            });

            const pagination = document.getElementById('pagination-container');
            pagination.innerHTML = '';

            if (!meta || meta.last_page <= 1) return;

            const prevButton = document.createElement('button');
            prevButton.textContent = 'Попередня';
            prevButton.className = `px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 ${meta.current_page === 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-100 dark:hover:bg-gray-600'}`;
            prevButton.disabled = meta.current_page === 1;
            prevButton.addEventListener('click', () => {
                if (meta.current_page > 1) {
                    state.page = meta.current_page - 1;
                    loadProducts();
                }
            });
            pagination.appendChild(prevButton);

            const pageInfo = document.createElement('span');
            pageInfo.textContent = `Сторінка ${meta.current_page} з ${meta.last_page}`;
            pageInfo.className = 'text-gray-600 dark:text-gray-300 px-4';
            pagination.appendChild(pageInfo);

            const nextButton = document.createElement('button');
            nextButton.textContent = 'Наступна';
            nextButton.className = `px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 ${meta.current_page === meta.last_page ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-100 dark:hover:bg-gray-600'}`;
            nextButton.disabled = meta.current_page === meta.last_page;
            nextButton.addEventListener('click', () => {
                if (meta.current_page < meta.last_page) {
                    state.page = meta.current_page + 1;
                    loadProducts();
                }
            });
            pagination.appendChild(nextButton);
        }
    </script>
@endsection

