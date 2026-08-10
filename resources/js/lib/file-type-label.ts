export type FileTypeLabelKey =
    | 'Archive file'
    | 'Audio'
    | 'File'
    | 'Image'
    | 'PDF document'
    | 'Presentation'
    | 'Spreadsheet'
    | 'Text file'
    | 'Video'
    | 'Word document';

const extensionLabels: Record<string, FileTypeLabelKey> = {
    '7z': 'Archive file',
    csv: 'Spreadsheet',
    doc: 'Word document',
    docx: 'Word document',
    gz: 'Archive file',
    json: 'Text file',
    md: 'Text file',
    odp: 'Presentation',
    ods: 'Spreadsheet',
    odt: 'Word document',
    pdf: 'PDF document',
    ppt: 'Presentation',
    pptx: 'Presentation',
    rar: 'Archive file',
    rtf: 'Word document',
    tar: 'Archive file',
    txt: 'Text file',
    xls: 'Spreadsheet',
    xlsm: 'Spreadsheet',
    xlsx: 'Spreadsheet',
    xml: 'Text file',
    zip: 'Archive file',
};

const normalizedExtension = (name: string): string => {
    const basename = name.split('/').pop() ?? name;
    const separator = basename.lastIndexOf('.');

    return separator > 0 && separator < basename.length - 1
        ? basename.slice(separator + 1).toLocaleLowerCase('en-US')
        : '';
};

const normalizedMimeType = (mimeType: string | null): string =>
    (mimeType?.split(';', 1)[0] ?? '').trim().toLocaleLowerCase('en-US');

export const fileTypeLabelKey = (
    name: string,
    mimeType: string | null,
): FileTypeLabelKey => {
    const extensionLabel = extensionLabels[normalizedExtension(name)];

    if (extensionLabel) {
        return extensionLabel;
    }

    const normalizedMime = normalizedMimeType(mimeType);

    if (normalizedMime.startsWith('image/')) {
        return 'Image';
    }

    if (normalizedMime.startsWith('video/')) {
        return 'Video';
    }

    if (normalizedMime.startsWith('audio/')) {
        return 'Audio';
    }

    if (
        normalizedMime === 'application/pdf' ||
        normalizedMime === 'application/x-pdf'
    ) {
        return 'PDF document';
    }

    if (
        normalizedMime === 'application/msword' ||
        normalizedMime ===
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ||
        normalizedMime === 'application/vnd.oasis.opendocument.text'
    ) {
        return 'Word document';
    }

    if (
        normalizedMime === 'application/vnd.ms-excel' ||
        normalizedMime ===
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ||
        normalizedMime === 'application/vnd.ms-excel.sheet.macroenabled.12' ||
        normalizedMime === 'application/vnd.oasis.opendocument.spreadsheet'
    ) {
        return 'Spreadsheet';
    }

    if (
        normalizedMime === 'application/vnd.ms-powerpoint' ||
        normalizedMime ===
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' ||
        normalizedMime === 'application/vnd.oasis.opendocument.presentation'
    ) {
        return 'Presentation';
    }

    if (
        normalizedMime.startsWith('text/') ||
        normalizedMime === 'application/json' ||
        normalizedMime === 'application/xml'
    ) {
        return 'Text file';
    }

    if (
        [
            'application/gzip',
            'application/vnd.rar',
            'application/x-7z-compressed',
            'application/x-rar-compressed',
            'application/x-tar',
            'application/zip',
        ].includes(normalizedMime)
    ) {
        return 'Archive file';
    }

    return 'File';
};
