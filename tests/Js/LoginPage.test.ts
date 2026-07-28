import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const loginPage = readFileSync(
    new URL('../../resources/js/pages/auth/Login.svelte', import.meta.url),
    'utf8',
);

test('password input precedes the forgot password link in keyboard order', () => {
    const passwordInput = loginPage.indexOf('<PasswordInput');
    const forgotPasswordLink = loginPage.indexOf("{t('Forgot password?')}");

    assert.notEqual(passwordInput, -1);
    assert.ok(forgotPasswordLink > passwordInput);
});
