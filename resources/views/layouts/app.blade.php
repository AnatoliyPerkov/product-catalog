<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сторінка товарів</title>
    @include('layouts.styles')
</head>
<body class="bg-gray-100 font-sans">
<div class="container mx-auto px-4">
    <div class="page-wrapper py-8">
        <div class="content bg-white p-6 rounded-lg shadow-md">
            @yield('content')
        </div>
    </div>
</div>
</body>

@include('layouts.scripts')

</body>
</html>
