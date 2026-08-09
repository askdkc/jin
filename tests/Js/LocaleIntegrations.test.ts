import assert from 'node:assert/strict';
import test from 'node:test';
import {
    emojiPickerLocaleKey,
    fileViewerLocale,
} from '../../resources/js/lib/locale-integrations.ts';

test('emoji picker locale mapping covers every supported application locale', () => {
    assert.deepEqual(
        {
            en: emojiPickerLocaleKey('en'),
            ja: emojiPickerLocaleKey('ja-JP'),
            fr: emojiPickerLocaleKey('fr-FR'),
            de: emojiPickerLocaleKey('de-DE'),
            zh_CN: emojiPickerLocaleKey('zh-CN'),
            zh_TW: emojiPickerLocaleKey('zh-TW'),
            es: emojiPickerLocaleKey('es-ES'),
            pt_BR: emojiPickerLocaleKey('pt-BR'),
            pt_PT: emojiPickerLocaleKey('pt-PT'),
            ko: emojiPickerLocaleKey('ko-KR'),
        },
        {
            en: 'en',
            ja: 'ja',
            fr: 'fr',
            de: 'de',
            zh_CN: 'zh_CN',
            zh_TW: 'zh_TW',
            es: 'es',
            pt_BR: 'pt_BR',
            pt_PT: 'pt_PT',
            ko: 'ko',
        },
    );
    assert.equal(emojiPickerLocaleKey(undefined), 'en');
    assert.equal(emojiPickerLocaleKey('unsupported'), 'en');
});

test('file viewer uses Simplified Chinese only when the selected locale is zh-CN', () => {
    assert.equal(fileViewerLocale('zh-CN'), 'zh-CN');
    assert.equal(fileViewerLocale('zh_CN'), 'zh-CN');
    assert.equal(fileViewerLocale('zh-TW'), 'en-US');
    assert.equal(fileViewerLocale('ja-JP'), 'en-US');
    assert.equal(fileViewerLocale(undefined), 'en-US');
});
