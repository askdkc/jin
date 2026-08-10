export const isFormSubmitShortcut = (
    event: Pick<
        KeyboardEvent,
        'key' | 'metaKey' | 'ctrlKey' | 'isComposing' | 'repeat'
    >,
): boolean =>
    event.key === 'Enter' &&
    (event.metaKey || event.ctrlKey) &&
    !event.isComposing &&
    !event.repeat;

export const submitFormById = (
    formId: string,
    documentReference: Pick<Document, 'getElementById'> = document,
): boolean => {
    const element = documentReference.getElementById(formId);
    const requestSubmit = (element as HTMLFormElement | null)?.requestSubmit;

    if (element?.tagName !== 'FORM' || typeof requestSubmit !== 'function') {
        return false;
    }

    requestSubmit.call(element);

    return true;
};
