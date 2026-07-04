(function() {
  window.__bridgePostMessage = function(message) {
    try {
      if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.__webview__) {
        return window.webkit.messageHandlers.__webview__.postMessage(message);
      }
      if (window.chrome && window.chrome.webview) {
        return window.chrome.webview.postMessage(message);
      }
    } catch(e) {
      console.error('Bridge postMessage error:', e);
    }
  };
})();
