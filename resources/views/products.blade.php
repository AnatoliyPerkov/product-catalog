@extends('layouts.app')

@section('title', 'Каталог товарів')

@section('content')
    <!-- Header -->
    <h1 class="text-3xl font-bold text-center mb-6">Каталог товарів</h1>

    <!-- Filters -->
    <div class="flex flex-col md:flex-row gap-4 mb-6">
        <div class="w-full md:w-1/4 bg-white p-4 rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">Фільтри</h2>
            <form method="GET" action="">
                <!-- Category Filter -->
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">Категорія</label>
                    <select name="category" id="categoryFilter" class="w-full p-2 border rounded">
                        <option value="">Усі категорії</option>
                        <option value="electronics" {{ request('category') === 'electronics' ? 'selected' : '' }}>Електроніка</option>
                        <option value="clothing" {{ request('category') === 'clothing' ? 'selected' : '' }}>Одяг</option>
                        <option value="books" {{ request('category') === 'books' ? 'selected' : '' }}>Книги</option>
                    </select>
                </div>
                <!-- Price Range Filter -->
                <div>
                    <label class="block text-sm font-medium mb-2">Ціна</label>
                    <input type="number" name="minPrice" id="minPrice" placeholder="Мін. ціна" value="{{ request('minPrice') }}" class="w-full p-2 border rounded mb-2">
                    <input type="number" name="maxPrice" id="maxPrice" placeholder="Макс. ціна" value="{{ request('maxPrice') }}" class="w-full p-2 border rounded">
                </div>
                <button type="submit" class="mt-4 w-full bg-blue-500 text-white p-2 rounded hover:bg-blue-600">Застосувати</button>
            </form>
        </div>

        <!-- Products Grid -->
        <div class="w-full md:w-3/4">
            <div id="productsGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Example static product cards (replace with dynamic data) -->
                <div class="product-card bg-white p-4 rounded-lg shadow">
                    <img src="{{ asset('images/no-image.jpg') }}" alt="Смартфон" class="w-full h-40 object-cover rounded mb-4">
                    <h3 class="text-lg font-semibold">Смартфон</h3>
                    <p class="text-gray-600">Категорія: electronics</p>
                    <p class="text-blue-500 font-bold">10000 грн</p>
                    <button class="mt-2 w-full bg-blue-500 text-white p-2 rounded hover:bg-blue-600">Додати в кошик</button>
                </div>
            </div>
            <!-- Pagination -->
            <div class="flex justify-center mt-6">
                <div id="pagination" class="flex gap-2">
                    <a href="?page=1" class="pagination-btn px-4 py-2 border rounded active">1</a>
                    <a href="?page=2" class="pagination-btn px-4 py-2 border rounded">2</a>
                    <a href="?page=3" class="pagination-btn px-4 py-2 border rounded">3</a>
                </div>
            </div>
        </div>
    </div>
@endsection
