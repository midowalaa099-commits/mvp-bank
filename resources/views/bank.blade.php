<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#172c37">
        <meta name="description" content="MVP Bank — a simpler space for your everyday money. A simulated banking experience.">
        <title>MVP Bank · Your money, in your rhythm.</title>
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    </head>
    <body>
        <div id="app"></div>
        <noscript>Please enable JavaScript to use MVP Bank.</noscript>
    </body>
</html>
