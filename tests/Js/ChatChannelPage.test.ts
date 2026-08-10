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
const contextPanel = readFileSync(
    new URL(
        '../../resources/js/components/ChatContextPanel.svelte',
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
const chatComposer = readFileSync(
    new URL(
        '../../resources/js/components/ChatComposer.svelte',
        import.meta.url,
    ),
    'utf8',
);
const richTextEditor = readFileSync(
    new URL(
        '../../resources/js/components/RichTextEditor.svelte',
        import.meta.url,
    ),
    'utf8',
);
const richTextContent = readFileSync(
    new URL(
        '../../resources/js/components/RichTextContent.svelte',
        import.meta.url,
    ),
    'utf8',
);
const button = readFileSync(
    new URL(
        '../../resources/js/components/ui/button/Button.svelte',
        import.meta.url,
    ),
    'utf8',
);
const emojiPicker = readFileSync(
    new URL(
        '../../resources/js/components/EmojiPickerButton.svelte',
        import.meta.url,
    ),
    'utf8',
);
const appCss = readFileSync(
    new URL('../../resources/css/app.css', import.meta.url),
    'utf8',
);
const japaneseTranslations = JSON.parse(
    readFileSync(new URL('../../lang/ja.json', import.meta.url), 'utf8'),
) as Record<string, string>;

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

test('manageable chat messages reveal edit and delete settings on hover', () => {
    assert.match(
        messageItem,
        /canManage && !editing[\s\S]*?group-hover:pointer-events-auto[\s\S]*?group-hover:opacity-100/,
    );
    assert.match(messageItem, /size="icon-xs"/);
    assert.match(
        messageItem,
        /<Settings data-icon="icon" aria-hidden="true" \/>/,
    );
    assert.match(messageItem, /class="w-32 min-w-32 p-1"/);
    assert.match(messageItem, /whitespace-nowrap/);
    assert.match(
        messageItem,
        /<Pencil[\s\S]*?data-icon="inline-start"[\s\S]*?\{t\('Edit'\)\}/,
    );
    assert.match(messageItem, /\{t\('Delete'\)\}/);
    assert.match(
        messageItem,
        /window\.confirm\(t\('Are you sure you want to delete this\?'\)\)/,
    );
    assert.match(messageItem, /<RichTextEditor[\s\S]*?value=\{message\.body\}/);
    assert.match(chatPage, /ChatController\.update\(/);
    assert.match(chatPage, /ChatController\.destroy\(/);
    assert.match(chatPage, /\.chat\.message\.updated/);
    assert.match(chatPage, /\.chat\.message\.deleted/);
    assert.equal(chatPage.match(/onUpdate=\{updateMessage\}/g)?.length, 3);
    assert.equal(chatPage.match(/onDelete=\{deleteMessage\}/g)?.length, 3);
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

test('top-level messages and thread replies share the rich chat composer', () => {
    assert.equal(chatPage.match(/<ChatComposer/g)?.length, 2);
    assert.match(chatPage, /onSubmit=\{sendMessage\}/);
    assert.match(chatPage, /onSubmit=\{sendReply\}/);
    assert.match(chatComposer, /<RichTextEditor/);
    assert.match(
        chatComposer,
        /<RichTextEditor[\s\S]*?\{attachmentUploadUrl\}/,
    );
    assert.match(chatComposer, /showFileInsert=\{false\}/);
    assert.match(
        chatPage,
        /const attachmentUploadUrl = \$derived\(\s*AttachmentController\.uploadInline\.url\(/,
    );
    assert.equal(
        chatPage.match(/<ChatComposer[\s\S]*?\{attachmentUploadUrl\}/g)?.length,
        2,
    );
    assert.match(chatComposer, /<EmojiPickerButton/);
    assert.match(chatComposer, /editor\?\.openMentionPicker\(\)/);
    assert.match(
        chatComposer,
        /onSubmitShortcut=\{\(\) => form\?\.requestSubmit\(\)\}/,
    );
    assert.match(richTextEditor, /type EditorMode = 'rich' \| 'markdown'/);
    assert.match(richTextEditor, /Code block language/);
});

test('code language selector sits immediately after the source code button', () => {
    assert.match(
        richTextEditor,
        /querySelector<HTMLElement>\(\s*'\[data-trix-attribute="code"\]'/,
    );
    assert.match(richTextEditor, /codeButton\?\.after\(codeLanguageSelect\)/);
    assert.match(
        richTextEditor,
        /class="rich-text-editor-code-language"[\s\S]*?hidden=\{mode !== 'rich' \|\| !inCodeBlock\}/,
    );
    assert.match(
        appCss,
        /trix-toolbar \.rich-text-editor-code-language\s*\{[\s\S]*?flex: 0 0 auto;[\s\S]*?margin-left: 0\.125rem;/,
    );
});

test('thread panel toggles between docked and full-screen views', () => {
    assert.match(chatPage, /let threadFullscreen = \$state\(false\)/);
    assert.match(chatPage, /const toggleThreadFullscreen = \(\): void =>/);
    assert.match(
        chatPage,
        /data-thread-view=\{threadFullscreen \? 'fullscreen' : 'panel'\}/,
    );
    assert.match(
        chatPage,
        /!threadFullscreen && 'lg:right-0 lg:left-auto lg:w-96'/,
    );
    assert.match(chatPage, /aria-pressed=\{threadFullscreen\}/);
    assert.match(chatPage, /onclick=\{toggleThreadFullscreen\}/);
    assert.match(chatPage, /\{#if threadFullscreen\}[\s\S]*?<Minimize2/);
    assert.match(chatPage, /\{:else\}[\s\S]*?<Maximize2/);
    assert.match(
        chatPage,
        /const closeThread = \(\): void => \{[\s\S]*?threadFullscreen = false;[\s\S]*?activeThread = null;/,
    );
    assert.equal(
        japaneseTranslations['Maximize thread'],
        'スレッドを全画面表示',
    );
    assert.equal(
        japaneseTranslations['Restore thread panel'],
        'スレッド表示を元に戻す',
    );
});

test('chat composer stays compact until the user interacts with it', () => {
    assert.match(chatPage, /xl:grid-cols-\[15rem_minmax\(0,1fr\)_16rem\]/);
    assert.match(chatComposer, /let expanded = \$state\(false\)/);
    assert.match(chatComposer, /data-expanded=\{expanded\}/);
    assert.match(chatComposer, /onpointerdown=\{\(\) => \(expanded = true\)\}/);
    assert.match(chatComposer, /onfocusin=\{\(\) => \(expanded = true\)\}/);
    assert.match(
        chatComposer,
        /\$effect\(\(\) => \{[\s\S]*?if \(allowEmpty\)[\s\S]*?expanded = true/,
    );
    assert.match(chatComposer, /!min-h-12/);
    assert.match(
        chatComposer,
        /: '\[&_\.rich-text-editor-shell\]:!rounded-xl[^']*!min-h-12/,
    );
    assert.match(
        chatComposer,
        /\{#if expanded\}[\s\S]*?class="flex min-h-9 items-center gap-0\.5 border-t/,
    );
    assert.equal(
        chatComposer.match(/class="(?:ml-1 )?size-7 rounded-full"/g)?.length,
        3,
    );
    assert.match(emojiPicker, /size="icon"[\s\S]*?class="size-7 rounded-full"/);
    assert.match(
        appCss,
        /\.chat-composer[\s\S]*?\.trix-button[\s\S]*?width: 1\.75rem;[\s\S]*?height: 1\.75rem;/,
    );
    assert.match(
        appCss,
        /\.rich-text-editor-shell\s+trix-toolbar\s+\.trix-button--icon::before\s*\{[\s\S]*?width: 1rem;[\s\S]*?height: 1rem;[\s\S]*?top: 50%;[\s\S]*?left: 50%;[\s\S]*?transform: translate\(-50%, -50%\);/,
    );
    assert.match(
        appCss,
        /\.chat-composer \.rich-text-editor-insert-tools svg,[\s\S]*?\.chat-composer \.rich-text-editor-mode-tools > button svg\s*\{[\s\S]*?width: 1rem;[\s\S]*?height: 1rem;/,
    );
    assert.match(
        appCss,
        /\.chat-composer\[data-expanded='false'\]\s+\.rich-text-editor-shell > trix-toolbar,[\s\S]*?display: none;/,
    );
    assert.match(
        appCss,
        /\.chat-composer\[data-expanded='false'\]\s+trix-editor\.rich-text-editor,[\s\S]*?grid-row: 1;/,
    );
    assert.match(
        appCss,
        /\.chat-composer\[data-expanded='false'\]\s+trix-editor\.rich-text-editor\s*\{[\s\S]*?display: grid;[\s\S]*?place-items: center start;[\s\S]*?font-size: 1rem;[\s\S]*?text-align: left;/,
    );
    assert.match(
        appCss,
        /\.chat-composer\[data-expanded='false'\]\s+trix-editor\.rich-text-editor::before\s*\{[\s\S]*?margin-left: 0\.25rem;/,
    );
    assert.match(
        appCss,
        /\.chat-composer\[data-expanded='false'\]\s+\.rich-text-editor-markdown\s*\{[\s\S]*?padding-block: 0\.75rem;[\s\S]*?padding-left: 1rem;[\s\S]*?font-size: 1rem;[\s\S]*?text-align: left;/,
    );
});

test('chat columns stay in the viewport while only messages scroll', () => {
    assert.match(chatPage, /data-chat-layout/);
    assert.match(chatPage, /h-\[calc\(100dvh-6rem-1px\)\]/);
    assert.match(
        chatPage,
        /<section class="flex min-h-0 min-w-0 flex-col bg-card\/70">/,
    );
    assert.match(
        chatPage,
        /data-chat-layout[\s\S]*?overflow-hidden[\s\S]*?aria-label=\{t\('Chat channels'\)\}/,
    );
    assert.match(
        chatPage,
        /bind:this=\{messageList\}[\s\S]*?min-h-0 flex-1 overflow-y-auto/,
    );
});

test('rich message composer keeps file drop and attachment-only sending', () => {
    const sendMessageHandler = chatPage.match(
        /const sendMessage = async[\s\S]*?const openThread = async/,
    )?.[0];

    assert.ok(sendMessageHandler);
    assert.match(chatPage, /ondrop=\{handleDrop\}/);
    assert.match(chatPage, /pendingAttachments\.length > 0/);
    assert.match(chatPage, /allowEmpty=\{pendingAttachments\.length > 0\}/);
    assert.match(sendMessageHandler, /pendingAttachments\.map/);
    assert.doesNotMatch(
        chatPage,
        /<textarea[\s\S]*?bind:value=\{sendHttp\.body\}/,
    );
});

test('chat supports semantic mentions, emoji insertion, and Shiki rendering', () => {
    assert.match(richTextEditor, /href="\/mentions\/\$\{user\.id\}"/);
    assert.match(richTextEditor, /export function openMentionPicker/);
    assert.match(richTextEditor, /export function insertText/);
    assert.match(emojiPicker, /emoji-picker-element/);
    assert.match(emojiPicker, /loadEmojiPickerLocalization/);
    assert.match(messageItem, /<RichTextContent/);
    assert.match(richTextContent, /import\('shiki\/bundle\/web'\)/);
    assert.match(richTextContent, /highlightCodeBlocks/);
});

test('inline image uploads remain in the Trix document while typing', () => {
    assert.match(
        richTextEditor,
        /const trix = Trix as unknown as TrixConstructor[\s\S]*?payload\.inline === true && editor\.editor[\s\S]*?new trix\.Attachment\(\{[\s\S]*?url: payload\.previewUrl,[\s\S]*?href: payload\.previewUrl,[\s\S]*?previewable: true,[\s\S]*?insertAttachmentWithFollowingParagraph\(attachment\)/,
    );
    assert.match(
        richTextEditor,
        /const insertAttachmentWithFollowingParagraph[\s\S]*?editorApi\.insertAttachment\(attachment\);[\s\S]*?requestAnimationFrame\(\(\) => \{[\s\S]*?getRangeOfAttachment\(attachment\)[\s\S]*?editorApi\.setSelectedRange\(attachmentRange\[1\]\);[\s\S]*?editorApi\.insertLineBreak\(\);[\s\S]*?editorApi\.setSelectedRange\(attachmentRange\[1\] \+ 1\);[\s\S]*?editor\.focus\(\);/,
    );
    assert.match(
        richTextEditor,
        /const insertTable[\s\S]*?insertAttachmentWithFollowingParagraph\([\s\S]*?new trix\.Attachment\(\{ content \}\)[\s\S]*?\);/,
    );
    assert.doesNotMatch(
        richTextEditor,
        /payload\.inline === true\s*\?\s*`<p><a[^`]*<img/,
    );
});

test('attachments embedded in a chat message are not rendered a second time', () => {
    assert.match(
        messageItem,
        /const standaloneAttachments = \$derived\([\s\S]*?message\.attachments\.filter\([\s\S]*?!message\.body\.includes\(`\/files\/\$\{file\.id\}\/`\)/,
    );
    assert.match(
        messageItem,
        /\{#if standaloneAttachments\.length > 0\}[\s\S]*?\{#each standaloneAttachments as file \(file\.id\)\}/,
    );
    assert.doesNotMatch(
        messageItem,
        /\{#each message\.attachments as file \(file\.id\)\}/,
    );
});

test('images dropped into Trix use only the inline attachment pipeline', () => {
    const dropHandler = chatPage.match(
        /const richTextEditorHandlesDrop[\s\S]*?const sendMessage = async/,
    )?.[0];

    assert.ok(dropHandler);
    assert.match(dropHandler, /event\s*\.composedPath\(\)/);
    assert.match(dropHandler, /target\.tagName === 'TRIX-EDITOR'/);
    assert.match(
        dropHandler,
        /if \(richTextEditorHandlesDrop\(event\)\) \{[\s\S]*?return;[\s\S]*?event\.preventDefault\(\);[\s\S]*?uploadFiles/,
    );
    assert.match(
        richTextEditor,
        /onAttachmentUploadChange\?: \(uploading: boolean\) => void/,
    );
    assert.match(
        richTextEditor,
        /pendingAttachmentUploads = Math\.max\([\s\S]*?onAttachmentUploadChange\?\.\(pendingAttachmentUploads > 0\)/,
    );
    assert.doesNotMatch(richTextEditor, /event\.stopPropagation\(\)/);
    assert.doesNotMatch(richTextEditor, /ondrop=\{stopEditorDropPropagation\}/);
    assert.match(chatComposer, /let attachmentUploading = \$state\(false\)/);
    assert.match(
        chatComposer,
        /const composerProcessing = \$derived\(processing \|\| attachmentUploading\)/,
    );
    assert.match(
        chatComposer,
        /onAttachmentUploadChange=\{\(uploading\) =>[\s\S]*?attachmentUploading = uploading/,
    );
});

test('successful chat sends clear focus and collapse the composer', () => {
    const successfulSubmit = chatComposer.match(
        /if \(sent\) \{[\s\S]*?\n {8}\}/,
    )?.[0];

    assert.ok(successfulSubmit);
    assert.match(successfulSubmit, /form\.reset\(\)/);
    assert.match(successfulSubmit, /hasContent = false/);
    assert.match(successfulSubmit, /expanded = false/);
    assert.match(successfulSubmit, /form\.contains\(focusedElement\)/);
    assert.match(successfulSubmit, /focusedElement\.blur\(\)/);
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

test('thread action is a compact labeled button beside the reaction picker', () => {
    assert.match(
        messageItem,
        /class="mt-2 flex items-center gap-1"[\s\S]*?<ReactionPicker[\s\S]*?\{#if onOpenThread\}[\s\S]*?<Button[\s\S]*?size="xs"[\s\S]*?class="rounded-full"/,
    );
    assert.match(messageItem, /aria-label=\{threadButtonLabel\(\)\}/);
    assert.match(messageItem, /title=\{threadButtonLabel\(\)\}/);
    assert.match(
        messageItem,
        /<MessageSquareText[\s\S]*?data-icon="inline-start"[\s\S]*?\{t\('Reply in thread'\)\}/,
    );
    assert.match(
        button,
        /xs: 'h-6 gap-1 rounded-md px-2 text-xs \[&_svg\]:size-3'/,
    );
    assert.match(button, /'icon-xs': 'size-6 rounded-md \[&_svg\]:size-3'/);
    assert.equal(japaneseTranslations['Reply in thread'], 'スレッド返信');
});

test('chat file panel renders compact authenticated file thumbnails', () => {
    assert.match(
        contextPanel,
        /import ProjectFileThumbnail from '@\/components\/ProjectFileThumbnail\.svelte'/,
    );
    assert.match(
        contextPanel,
        /<ProjectFileThumbnail[\s\S]*?teamSlug=\{currentTeam\.slug\}[\s\S]*?projectId=\{project\.id\}[\s\S]*?fileId=\{file\.id\}[\s\S]*?mimeType=\{file\.mimeType\}[\s\S]*?thumbnailUrl=\{null\}[\s\S]*?variant="summary"/,
    );
});

test('chat context confirms to-do unlinking and previews supported files', () => {
    const unlinkAction = contextPanel.match(
        /const unlinkTodo = \(todo: ChatTodo\): void => \{[\s\S]*?\n {4}\};/,
    )?.[0];

    assert.ok(unlinkAction);
    assert.match(
        unlinkAction,
        /window\.confirm\([\s\S]*?t\('Remove :todo from #:channel\?'[\s\S]*?todo: todo\.title[\s\S]*?channel: selectedChannel\.name/,
    );
    assert.ok(
        unlinkAction.indexOf('window.confirm') <
            unlinkAction.indexOf('router.patch'),
    );
    assert.match(contextPanel, /import Eye from 'lucide-svelte\/icons\/eye'/);
    assert.match(
        contextPanel,
        /aria-label=\{t\('Preview :file'[\s\S]*?<Eye data-icon="icon" aria-hidden="true" \/>/,
    );
    assert.match(
        contextPanel,
        /\{:else\}[\s\S]*?<Button asChild variant="ghost" size="icon">[\s\S]*?aria-label=\{t\('Download'\)\}/,
    );
    assert.equal(
        japaneseTranslations['Remove :todo from #:channel?'],
        '「:todo」を#:channelから外しますか？',
    );
});

test('chat reactions update immediately and through the project Echo channel', () => {
    assert.match(chatPage, /const toggleMessageReaction = async/);
    assert.match(chatPage, /setMessageReactions\(response\.messageId/);
    assert.match(chatPage, /\.chat\.message\.reaction\.updated/);
    assert.match(chatPage, /reactorIds\.includes\(currentUserId\)/);
    assert.match(reactionPicker, /onToggle\?: \(reaction: string\)/);
    assert.match(reactionPicker, /event\.preventDefault\(\)/);
});
