<script lang="ts">
    import { Link, router } from '@inertiajs/svelte';
    import LogOut from 'lucide-svelte/icons/log-out';
    import UserRound from 'lucide-svelte/icons/user-round';
    import {
        DropdownMenuGroup,
        DropdownMenuItem,
        DropdownMenuLabel,
        DropdownMenuSeparator,
    } from '@/components/ui/dropdown-menu';
    import UserInfo from '@/components/UserInfo.svelte';
    import { t } from '@/lib/i18n';
    import { profileDialog } from '@/lib/profile-dialog.svelte';
    import { logout } from '@/routes';
    import type { User } from '@/types';

    let {
        user,
    }: {
        user: User;
    } = $props();

    function handleLogout(propsOnClick?: () => void) {
        return () => {
            propsOnClick?.();
            router.flushAll();
        };
    }

    function handleProfile(propsOnClick?: () => void) {
        return () => {
            propsOnClick?.();
            requestAnimationFrame(() => profileDialog.show());
        };
    }
</script>

<DropdownMenuLabel class="p-0 font-normal">
    <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
        <UserInfo {user} showEmail={true} />
    </div>
</DropdownMenuLabel>
<DropdownMenuSeparator />
<DropdownMenuGroup>
    <DropdownMenuItem asChild>
        {#snippet children(props)}
            <button
                type="button"
                class={props.class}
                onclick={handleProfile(props.onClick)}
                data-test="open-profile-dialog"
            >
                <UserRound />
                {t('Profile')}
            </button>
        {/snippet}
    </DropdownMenuItem>
</DropdownMenuGroup>
<DropdownMenuSeparator />
<DropdownMenuItem asChild>
    {#snippet children(props)}
        <Link
            class={props.class}
            href={logout()}
            as="button"
            onclick={handleLogout(props.onClick)}
            data-test="logout-button"
        >
            <LogOut />
            {t('Log out')}
        </Link>
    {/snippet}
</DropdownMenuItem>
