import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const chatPage = readFileSync(
    new URL(
        '../../resources/js/pages/projects/chat/Show.svelte',
        import.meta.url,
    ),
    'utf8',
);
const channelActions = readFileSync(
    new URL(
        '../../resources/js/components/ChatChannelActions.svelte',
        import.meta.url,
    ),
    'utf8',
);
const messageItem = readFileSync(
    new URL(
        '../../resources/js/components/ChatMessageItem.svelte',
        import.meta.url,
    ),
    'utf8',
);
const reactionPicker = readFileSync(
    new URL(
        '../../resources/js/components/ReactionPicker.svelte',
        import.meta.url,
    ),
    'utf8',
);

test('successful channel creation replaces channel-scoped page state', () => {
    const channelForm = chatPage.match(
        /<Form[\s\S]*?ChatChannelController\.store\.form[\s\S]*?<\/Form>/,
    )?.[0];

    assert.ok(channelForm);
    assert.match(channelForm, /options=\{\{ preserveState: 'errors' \}\}/);
});

test('manageable channels reveal settings on hover', () => {
    assert.match(
        chatPage,
        /\{#if channel\.canManage\}[\s\S]*?<ChatChannelActions/,
    );
    assert.match(channelActions, /group-hover\/channel:opacity-100/);
    assert.match(channelActions, /aria-label=\{t\('Channel settings'\)\}/);
});

test('channel settings use Wayfinder update and delete forms', () => {
    assert.match(
        channelActions,
        /ChatChannelController\.update\.form\(routeArguments\)/,
    );
    assert.match(
        channelActions,
        /ChatChannelController\.destroy\.form\(routeArguments\)/,
    );
    assert.match(channelActions, /name="selected_channel_id"/);
    assert.match(channelActions, /options=\{\{ preserveState: 'errors' \}\}/);
});

test('message composer is compact until the textarea receives focus', () => {
    assert.match(chatPage, /let composerExpanded = \$state\(false\)/);
    assert.match(chatPage, /data-expanded=\{composerExpanded\}/);
    assert.match(chatPage, /rows=\{composerExpanded \? 3 : 1\}/);
    assert.match(chatPage, /onfocus=\{expandComposer\}/);
    assert.match(chatPage, /'flex h-12 items-center gap-1\.5 px-2'/);
    assert.match(
        chatPage,
        /\{#if composerExpanded\}[\s\S]*?\{t\('Drop files to attach'\)\}[\s\S]*?\{\/if\}/,
    );
});

test('message composer keeps the existing send and file drop behavior', () => {
    const sendMessageHandler = chatPage.match(
        /const sendMessage = async[\s\S]*?const openThread = async/,
    )?.[0];

    assert.ok(sendMessageHandler);
    assert.match(
        chatPage,
        /event\.key === 'Enter'[\s\S]*?!event\.shiftKey[\s\S]*?void sendMessage\(\)/,
    );
    assert.match(
        chatPage,
        /bind:this=\{composerShell\}[\s\S]*?ondrop=\{handleDrop\}/,
    );
    assert.match(chatPage, /pendingAttachments\.length > 0/);
    assert.doesNotMatch(sendMessageHandler, /composerExpanded = false/);
});

test('every chat message surface exposes the shared emoji reaction picker', () => {
    assert.match(messageItem, /<ReactionPicker/);
    assert.match(messageItem, /ChatController\.toggleReaction\.url/);
    assert.equal(
        chatPage.match(/onToggleReaction=\{toggleMessageReaction\}/g)?.length,
        3,
    );
    assert.match(reactionPicker, /SmilePlus/);
    assert.match(reactionPicker, /loadEmojiPickerLocalization/);
});

test('chat reactions update immediately and through the project Echo channel', () => {
    assert.match(chatPage, /const toggleMessageReaction = async/);
    assert.match(chatPage, /setMessageReactions\(response\.messageId/);
    assert.match(chatPage, /\.chat\.message\.reaction\.updated/);
    assert.match(chatPage, /reactorIds\.includes\(currentUserId\)/);
    assert.match(reactionPicker, /onToggle\?: \(reaction: string\)/);
    assert.match(reactionPicker, /event\.preventDefault\(\)/);
});
