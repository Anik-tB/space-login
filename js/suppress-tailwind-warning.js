// Suppress Tailwind CDN production warning (development environment)
// This script should be loaded after Tailwind CDN to suppress the console warning
(function() {
    if (typeof console !== 'undefined' && console.warn) {
        const originalWarn = console.warn;
        console.warn = function(...args) {
            // Suppress Tailwind CDN production warning
            if (args[0] && typeof args[0] === 'string' && 
                (args[0].includes('cdn.tailwindcss.com should not be used in production') ||
                 args[0].includes('cdn.tailwindcss.com'))) {
                return; // Suppress this specific warning
            }
            originalWarn.apply(console, args);
        };
    }
})();

