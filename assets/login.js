(() => {
    'use strict';
    const range = document.getElementById('sliderCaptchaRange');
    const fill = document.getElementById('sliderCaptchaFill');
    const state = document.getElementById('sliderCaptchaState');
    const tokenField = document.getElementById('sliderCaptchaToken');
    const captcha = document.getElementById('sliderCaptcha');
    const submit = document.getElementById('loginSubmitBtn');
    if (!range || !fill || !state || !tokenField || !captcha || !submit) return;

    let solved = false;

    function label(text) {
        const t = window.SolanaceI18n?.language === 'en'
            ? window.SolanaceI18n.translate(text)
            : text;
        state.textContent = t;
    }

    function reset() {
        solved = false;
        range.value = '0';
        fill.style.width = '0%';
        tokenField.value = '';
        submit.disabled = true;
        captcha.classList.remove('solved');
        label('Протяните ползунок вправо');
    }

    function update() {
        if (solved) return;
        const value = Math.max(0, Math.min(100, Number(range.value || 0)));
        fill.style.width = `${value}%`;
        if (value >= 98) {
            solved = true;
            range.value = '100';
            fill.style.width = '100%';
            captcha.classList.add('solved');
            tokenField.value = captcha.dataset.token || '';
            submit.disabled = tokenField.value === '';
            label('Готово');
        }
    }

    range.addEventListener('input', update);
    range.addEventListener('change', () => {
        if (!solved) reset();
    });
    window.addEventListener('solanace:languagechange', () => {
        label(solved ? 'Готово' : 'Протяните ползунок вправо');
    });
    reset();
})();
