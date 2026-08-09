export function interpolateTranslation(
    translation: string,
    replacements: Record<string, string>,
): string {
    let result = translation;
    const entries = Object.entries(replacements).sort(
        ([left], [right]) => right.length - left.length,
    );

    for (const [name, value] of entries) {
        result = result.replaceAll(`:${name}`, () => value);
    }

    return result;
}
