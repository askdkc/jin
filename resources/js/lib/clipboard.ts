type ClipboardWriter = Pick<Clipboard, 'writeText'>;

type ClipboardFallback = (text: string) => boolean;

export function copyTextWithSelection(
    text: string,
    documentReference: Document | undefined = globalThis.document,
): boolean {
    if (!documentReference?.body) {
        return false;
    }

    const textArea = documentReference.createElement('textarea');
    textArea.value = text;
    textArea.setAttribute('readonly', '');
    textArea.style.position = 'fixed';
    textArea.style.opacity = '0';
    textArea.style.pointerEvents = 'none';
    documentReference.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    textArea.setSelectionRange(0, text.length);

    try {
        return documentReference.execCommand('copy');
    } catch {
        return false;
    } finally {
        textArea.remove();
    }
}

export async function copyTextToClipboard(
    text: string,
    clipboard: ClipboardWriter | undefined = globalThis.navigator?.clipboard,
    fallback: ClipboardFallback = copyTextWithSelection,
): Promise<boolean> {
    if (clipboard) {
        try {
            await clipboard.writeText(text);

            return true;
        } catch {
            // The Clipboard API can be unavailable at runtime on non-secure origins.
        }
    }

    return fallback(text);
}
