function toggleSmtp() {
    const selectedMethod = document.querySelector('input[name="use_smtp"]:checked');
    const smtpSettings   = document.getElementById('smtp-settings');
    const resendSettings = document.getElementById('resend-settings');
    if (!selectedMethod || !smtpSettings || !resendSettings) return;

    smtpSettings.classList.toggle('smtp-disabled',   selectedMethod.value !== '1');
    resendSettings.classList.toggle('smtp-disabled', selectedMethod.value !== '2');
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
