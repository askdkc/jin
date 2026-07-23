class ProfileDialogState {
    open = $state(false);

    show(): void {
        this.open = true;
    }

    close(): void {
        this.open = false;
    }
}

export const profileDialog = new ProfileDialogState();
