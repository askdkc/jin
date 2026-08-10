class DashboardActivityPanelState {
    open = $state(false);

    show(): void {
        this.open = true;
    }

    hide(): void {
        this.open = false;
    }
}

export const dashboardActivityPanel = new DashboardActivityPanelState();
