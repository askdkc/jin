<script lang="ts">
    import {
        Avatar,
        AvatarFallback,
        AvatarImage,
    } from '@/components/ui/avatar';
    import { getInitials } from '@/lib/initials';

    let {
        name,
        avatar = null,
        class: className = '',
        fallbackClass = '',
    }: {
        name: string;
        avatar?: string | null;
        class?: string;
        fallbackClass?: string;
    } = $props();

    let failedAvatar = $state<string | null>(null);
    let avatarSource = $derived(
        avatar && avatar !== failedAvatar ? avatar : null,
    );

    const handleAvatarError = (): void => {
        failedAvatar = avatar;
    };
</script>

<Avatar class={className}>
    {#if avatarSource}
        <AvatarImage
            src={avatarSource}
            alt={name}
            class="object-cover"
            onerror={handleAvatarError}
        />
    {/if}
    <AvatarFallback class={fallbackClass}>
        {getInitials(name) || '?'}
    </AvatarFallback>
</Avatar>
