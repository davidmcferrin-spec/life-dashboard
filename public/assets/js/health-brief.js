(function () {
    const slot = document.getElementById('digest-health');
    if (!slot || !window.LD) return;
    window.LD.api.get('/health/brief').then(function (data) {
        if (!data.brief || !data.brief.body) return;
        slot.hidden = false;
        slot.textContent = data.brief.day + ': ' + data.brief.body;
    }).catch(function () {});
})();
