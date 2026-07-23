<script module lang="ts">
    import { edit } from '@/routes/profile';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    };
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import DeleteUser from '@/components/DeleteUser.svelte';
    import Heading from '@/components/Heading.svelte';
    import ProfileEditor from '@/components/ProfileEditor.svelte';
    import { t } from '@/lib/i18n';
</script>

<AppHead title={t('Profile settings')} />

<h1 class="sr-only">{t('Profile settings')}</h1>

<div class="flex flex-col gap-6">
    <Heading
        variant="small"
        title={t('Profile')}
        description={t('Update your profile information and avatar')}
    />

    <ProfileEditor idPrefix="profile-page" />
</div>

<DeleteUser />
