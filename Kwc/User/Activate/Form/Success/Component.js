var onReady = require('kwf/commonjs/on-ready');

onReady.onRender('.kwcClass', function(el, config) {
    window.setTimeout(function() {
        window.location.href = config.redirectUrl;
    }, 3000);
});
