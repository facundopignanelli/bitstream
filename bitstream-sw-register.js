if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('../../../wp-content/plugins/bitstream/bitstream-service-worker.js')
      .then(function(reg) {
        console.log('BitStream SW registration successful, scope is:', reg.scope);
      })
      .catch(function(err) {
        console.log('BitStream SW registration failed:', err);
      });
  });
}