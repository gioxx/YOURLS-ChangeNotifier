function toggleSmtp() {
    const selectedMethod = document.querySelector('input[name="use_smtp"]:checked');
    const smtpSettings = document.getElementById('smtp-settings');
    if (!selectedMethod || !smtpSettings) {
        return;
    }

    if (selectedMethod.value === '1') {
        smtpSettings.classList.remove('smtp-disabled');
    } else {
        smtpSettings.classList.add('smtp-disabled');
    }
}

function toggleSmtpAuth() {
    const requireAuth = document.querySelector('input[name="smtp_auth"]');
    const smtpAuth = document.getElementById('smtp-auth');
    if (!requireAuth || !smtpAuth) {
        return;
    }

    if (requireAuth.checked) {
        smtpAuth.classList.remove('smtp-disabled');
    } else {
        smtpAuth.classList.add('smtp-disabled');
    }
}
