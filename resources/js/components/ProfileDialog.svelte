<script lang="ts">
    import ProfileEditor from '@/components/ProfileEditor.svelte';
    import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
    import { t } from '@/lib/i18n';
    import { profileDialog } from '@/lib/profile-dialog.svelte';
</script>

<Dialog bind:open={profileDialog.open}>
    <DialogContent class="max-w-2xl p-0">
        <div class="sr-only">
            <DialogTitle>{t('Profile')}</DialogTitle>
        </div>
        <div class="px-6 py-8 sm:px-10">
            <ProfileEditor
                idPrefix="profile-dialog"
                onSaved={() => profileDialog.close()}
            />
        </div>
    </DialogContent>
</Dialog>
