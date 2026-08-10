<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import Pencil from 'lucide-svelte/icons/pencil';
    import Settings from 'lucide-svelte/icons/settings';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import ChatChannelController from '@/actions/App/Http/Controllers/ChatChannelController';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import {
        Dialog,
        DialogContent,
        DialogDescription,
        DialogFooter,
        DialogTitle,
    } from '@/components/ui/dialog';
    import {
        DropdownMenu,
        DropdownMenuContent,
        DropdownMenuGroup,
        DropdownMenuItem,
        DropdownMenuSeparator,
        DropdownMenuTrigger,
    } from '@/components/ui/dropdown-menu';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { t } from '@/lib/i18n';
    import type { ChatChannel, ChatProject, Team } from '@/types';

    let {
        currentTeam,
        project,
        channel,
        selectedChannelId,
        canDelete,
    }: {
        currentTeam: Team;
        project: ChatProject;
        channel: ChatChannel;
        selectedChannelId: number;
        canDelete: boolean;
    } = $props();

    let renameDialogOpen = $state(false);
    let deleteDialogOpen = $state(false);

    const routeArguments = $derived({
        current_team: currentTeam.slug,
        project: project.id,
        chat_channel: channel.id,
    });
</script>

<DropdownMenu
    class="absolute top-1/2 right-1 z-10 -translate-y-1/2 opacity-0 transition-opacity group-focus-within/channel:opacity-100 group-hover/channel:opacity-100"
>
    <DropdownMenuTrigger asChild>
        {#snippet children(triggerProps)}
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="size-7 text-inherit hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                onclick={triggerProps.onclick}
                aria-expanded={triggerProps['aria-expanded']}
                data-state={triggerProps['data-state']}
                aria-label={t('Channel settings')}
                title={t('Channel settings')}
            >
                <Settings aria-hidden="true" />
            </Button>
        {/snippet}
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" sideOffset={4}>
        <DropdownMenuGroup>
            <DropdownMenuItem asChild>
                {#snippet children(itemProps)}
                    <button
                        type="button"
                        class={itemProps.class}
                        onclick={() => {
                            itemProps.onClick?.();
                            renameDialogOpen = true;
                        }}
                    >
                        <Pencil aria-hidden="true" />
                        {t('Rename channel')}
                    </button>
                {/snippet}
            </DropdownMenuItem>
        </DropdownMenuGroup>
        {#if canDelete}
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    {#snippet children(itemProps)}
                        <button
                            type="button"
                            class={`${itemProps.class ?? ''} text-destructive hover:text-destructive`}
                            onclick={() => {
                                itemProps.onClick?.();
                                deleteDialogOpen = true;
                            }}
                        >
                            <Trash2 aria-hidden="true" />
                            {t('Delete channel')}
                        </button>
                    {/snippet}
                </DropdownMenuItem>
            </DropdownMenuGroup>
        {/if}
    </DropdownMenuContent>
</DropdownMenu>

<Dialog bind:open={renameDialogOpen}>
    <DialogContent class="sm:max-w-md">
        <DialogTitle>{t('Rename channel')}</DialogTitle>
        <DialogDescription>
            {t('Change the name of #:channel.', { channel: channel.name })}
        </DialogDescription>
        <Form
            {...ChatChannelController.update.form(routeArguments)}
            options={{ preserveState: 'errors' }}
            onSuccess={() => (renameDialogOpen = false)}
            class="mt-5 flex flex-col gap-4"
        >
            {#snippet children({ errors, processing })}
                <input
                    type="hidden"
                    name="selected_channel_id"
                    value={selectedChannelId}
                />
                <div class="flex flex-col gap-2">
                    <Label for={`rename-chat-channel-${channel.id}`}>
                        {t('Channel name')}
                    </Label>
                    <Input
                        id={`rename-chat-channel-${channel.id}`}
                        name="name"
                        value={channel.name}
                        maxlength={80}
                        required
                        autofocus
                    />
                    <InputError message={errors.name} />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onclick={() => (renameDialogOpen = false)}
                    >
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {t('Save')}
                    </Button>
                </DialogFooter>
            {/snippet}
        </Form>
    </DialogContent>
</Dialog>

<Dialog bind:open={deleteDialogOpen}>
    <DialogContent class="sm:max-w-md">
        <Form
            {...ChatChannelController.destroy.form(routeArguments)}
            options={{ preserveState: 'errors' }}
            onSuccess={() => (deleteDialogOpen = false)}
            class="flex flex-col gap-5"
        >
            {#snippet children({ errors, processing })}
                <input
                    type="hidden"
                    name="selected_channel_id"
                    value={selectedChannelId}
                />
                <div class="space-y-2">
                    <DialogTitle>{t('Delete channel')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'This action cannot be undone. All messages in #:channel will be permanently deleted.',
                            { channel: channel.name },
                        )}
                    </DialogDescription>
                    <InputError message={errors.channel} />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onclick={() => (deleteDialogOpen = false)}
                    >
                        {t('Cancel')}
                    </Button>
                    <Button
                        type="submit"
                        variant="destructive"
                        disabled={processing}
                    >
                        {t('Delete channel')}
                    </Button>
                </DialogFooter>
            {/snippet}
        </Form>
    </DialogContent>
</Dialog>
