import assert from 'node:assert/strict';
import test from 'node:test';
import {
    copyTextToClipboard,
    copyTextWithSelection,
} from '../../resources/js/lib/clipboard.ts';

test('clipboard API copies text without using the fallback', async () => {
    let writtenText = '';
    let fallbackCalled = false;

    const copied = await copyTextToClipboard(
        'subscription-url',
        {
            async writeText(text: string): Promise<void> {
                writtenText = text;
            },
        },
        () => {
            fallbackCalled = true;

            return true;
        },
    );

    assert.equal(copied, true);
    assert.equal(writtenText, 'subscription-url');
    assert.equal(fallbackCalled, false);
});

test('clipboard copy falls back when the API is unavailable or rejected', async () => {
    const fallbackValues: string[] = [];
    const fallback = (text: string): boolean => {
        fallbackValues.push(text);

        return true;
    };

    assert.equal(
        await copyTextToClipboard('unavailable', undefined, fallback),
        true,
    );
    assert.equal(
        await copyTextToClipboard(
            'rejected',
            {
                async writeText(): Promise<void> {
                    throw new Error('Clipboard API unavailable');
                },
            },
            fallback,
        ),
        true,
    );
    assert.deepEqual(fallbackValues, ['unavailable', 'rejected']);
});

test('selection fallback copies text and removes its temporary textarea', () => {
    let appended = false;
    let focused = false;
    let selected = false;
    let removed = false;
    let selectedRange: [number, number] | null = null;
    const attributes = new Map<string, string>();
    const textArea = {
        value: '',
        style: {
            position: '',
            opacity: '',
            pointerEvents: '',
        },
        setAttribute(name: string, value: string): void {
            attributes.set(name, value);
        },
        focus(): void {
            focused = true;
        },
        select(): void {
            selected = true;
        },
        setSelectionRange(start: number, end: number): void {
            selectedRange = [start, end];
        },
        remove(): void {
            removed = true;
        },
    };
    const documentReference = {
        body: {
            appendChild(element: typeof textArea): void {
                assert.equal(element, textArea);
                appended = true;
            },
        },
        createElement(tagName: string): typeof textArea {
            assert.equal(tagName, 'textarea');

            return textArea;
        },
        execCommand(command: string): boolean {
            assert.equal(command, 'copy');

            return true;
        },
    } as unknown as Document;

    assert.equal(
        copyTextWithSelection('subscription-url', documentReference),
        true,
    );
    assert.equal(textArea.value, 'subscription-url');
    assert.equal(attributes.get('readonly'), '');
    assert.equal(appended, true);
    assert.equal(focused, true);
    assert.equal(selected, true);
    assert.deepEqual(selectedRange, [0, 16]);
    assert.equal(removed, true);
});
