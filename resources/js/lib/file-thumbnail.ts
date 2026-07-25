export type FileThumbnailSource = {
    kind: 'generated' | 'original';
    url: string;
};

type FileThumbnailSourceOptions = {
    mimeType: string | null;
    thumbnailUrl: string | null;
    originalPreviewUrl: string | null;
    failedUrls?: readonly string[];
};

export const nextFileThumbnailSource = ({
    mimeType,
    thumbnailUrl,
    originalPreviewUrl,
    failedUrls = [],
}: FileThumbnailSourceOptions): FileThumbnailSource | null => {
    const candidates: FileThumbnailSource[] = [];

    if (thumbnailUrl) {
        candidates.push({ kind: 'generated', url: thumbnailUrl });
    }

    if (
        mimeType?.startsWith('image/') &&
        originalPreviewUrl &&
        originalPreviewUrl !== thumbnailUrl
    ) {
        candidates.push({ kind: 'original', url: originalPreviewUrl });
    }

    const failed = new Set(failedUrls);

    return candidates.find((candidate) => !failed.has(candidate.url)) ?? null;
};
