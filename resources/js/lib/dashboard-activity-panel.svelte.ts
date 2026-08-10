class DashboardActivityPanelState {
    open = $state(true);

    toggle(): void {
        this.open = !this.open;
    }
}

export const dashboardActivityPanel = new DashboardActivityPanelState();
