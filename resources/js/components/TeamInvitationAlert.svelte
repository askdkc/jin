<script lang="ts">
    import Info from 'lucide-svelte/icons/info';
    import { Alert, AlertDescription } from '@/components/ui/alert';
    import { t } from '@/lib/i18n';
    import type { TeamInvitationContext } from '@/types';

    let {
        invitation,
        action,
    }: {
        invitation: TeamInvitationContext;
        action: 'Log in' | 'Register';
    } = $props();

    const invitationMessage = $derived(
        action === 'Log in'
            ? t('Log in to join the ":team" team.', {
                  team: invitation.teamName,
              })
            : t('Register to join the ":team" team.', {
                  team: invitation.teamName,
              }),
    );
</script>

<div data-test="team-invitation-alert">
    <Alert
        class="border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/50 dark:text-blue-100 [&>svg]:text-blue-600 dark:[&>svg]:text-blue-400"
    >
        <Info class="size-4" />
        <AlertDescription class="text-blue-900 dark:text-blue-100">
            {invitationMessage}
        </AlertDescription>
    </Alert>
</div>
