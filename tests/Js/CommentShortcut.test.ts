import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import {
    isFormSubmitShortcut,
    submitFormById,
} from '../../resources/js/lib/form-submit.ts';

test('Command+Enter and Control+Enter are form submission shortcuts', () => {
    const keyboardEvent = {
        key: 'Enter',
        metaKey: false,
        ctrlKey: false,
        isComposing: false,
        repeat: false,
    };

    assert.equal(
        isFormSubmitShortcut({ ...keyboardEvent, metaKey: true }),
        true,
    );
    assert.equal(
        isFormSubmitShortcut({ ...keyboardEvent, ctrlKey: true }),
        true,
    );
});

test('plain, composing, and repeated Enter presses do not submit', () => {
    const keyboardEvent = {
        key: 'Enter',
        metaKey: false,
        ctrlKey: false,
        isComposing: false,
        repeat: false,
    };

    assert.equal(isFormSubmitShortcut(keyboardEvent), false);
    assert.equal(
        isFormSubmitShortcut({
            ...keyboardEvent,
            metaKey: true,
            isComposing: true,
        }),
        false,
    );
    assert.equal(
        isFormSubmitShortcut({
            ...keyboardEvent,
            ctrlKey: true,
            repeat: true,
        }),
        false,
    );
    assert.equal(
        isFormSubmitShortcut({
            ...keyboardEvent,
            key: 'Escape',
            metaKey: true,
        }),
        false,
    );
});

test('form submission helper uses native requestSubmit', () => {
    let submissions = 0;
    const form = {
        tagName: 'FORM',
        requestSubmit(): void {
            submissions++;
        },
    };
    const documentReference = {
        getElementById(id: string) {
            assert.equal(id, 'comment-form');

            return form;
        },
    } as unknown as Pick<Document, 'getElementById'>;

    assert.equal(submitFormById('comment-form', documentReference), true);
    assert.equal(submissions, 1);
});

test('form submission helper ignores missing and non-form elements', () => {
    const documentReference = {
        getElementById(id: string) {
            return id === 'not-a-form'
                ? { tagName: 'DIV', requestSubmit() {} }
                : null;
        },
    } as unknown as Pick<Document, 'getElementById'>;

    assert.equal(submitFormById('missing', documentReference), false);
    assert.equal(submitFormById('not-a-form', documentReference), false);
});

const commentForms = [
    ['projects/messages/Show.svelte', 'store'],
    ['projects/todos/Show.svelte', 'storeTodo'],
    ['projects/todos/ListShow.svelte', 'storeList'],
    ['projects/files/Show.svelte', 'storeFile'],
    ['projects/schedule/Show.svelte', 'storeScheduleEvent'],
    ['projects/card-tables/ColumnShow.svelte', 'storeCardColumn'],
    ['projects/check-ins/Show.svelte', 'storeCheckInAnswer'],
] as const;

for (const [pagePath, controllerAction] of commentForms) {
    test(`${pagePath} submits new comments with the editor shortcut`, () => {
        const source = readFileSync(
            new URL(`../../resources/js/pages/${pagePath}`, import.meta.url),
            'utf8',
        );
        const formStart = source.indexOf(
            `CommentController.${controllerAction}.form`,
        );
        const formEnd = source.indexOf('</Form>', formStart);

        assert.notEqual(formStart, -1);
        assert.notEqual(formEnd, -1);

        const commentForm = source.slice(formStart, formEnd);

        assert.match(commentForm, /onSubmitShortcut=/);
        assert.match(commentForm, /!processing/);
        assert.match(commentForm, /submitFormById/);
    });
}
