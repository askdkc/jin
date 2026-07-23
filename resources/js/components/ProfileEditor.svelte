<script lang="ts">
    import { Form, page, router } from '@inertiajs/svelte';
    import Camera from 'lucide-svelte/icons/camera';
    import Trash2 from 'lucide-svelte/icons/trash-2';
    import { onDestroy } from 'svelte';
    import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
    import InputError from '@/components/InputError.svelte';
    import TextLink from '@/components/TextLink.svelte';
    import {
        Avatar,
        AvatarFallback,
        AvatarImage,
    } from '@/components/ui/avatar';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';
    import { getInitials } from '@/lib/initials';
    import { t } from '@/lib/i18n';
    import { send } from '@/routes/verification';

    let {
        idPrefix = 'profile',
        onSaved,
    }: {
        idPrefix?: string;
        onSaved?: () => void;
    } = $props();

    const user = $derived(page.props.auth.user);
    let avatarInput = $state<HTMLInputElement>();
    let avatarPreview = $state<string | null>(null);
    let removingAvatar = $state(false);
    const avatarSource = $derived(avatarPreview ?? user.avatar);
    const fieldId = (name: string): string => `${idPrefix}-${name}`;

    const clearAvatarPreview = (): void => {
        if (avatarPreview) {
            URL.revokeObjectURL(avatarPreview);
            avatarPreview = null;
        }
    };
    const previewAvatar = (event: Event): void => {
        clearAvatarPreview();

        const input = event.currentTarget as HTMLInputElement;
        const file = input.files?.[0];

        if (file) {
            avatarPreview = URL.createObjectURL(file);
        }
    };
    const resetAvatarInput = (): void => {
        clearAvatarPreview();

        if (avatarInput) {
            avatarInput.value = '';
        }
    };
    const profileSaved = (): void => {
        resetAvatarInput();
        onSaved?.();
    };
    const removeAvatar = (): void => {
        router.delete(ProfileController.destroyAvatar().url, {
            preserveScroll: true,
            onStart: () => {
                removingAvatar = true;
            },
            onSuccess: resetAvatarInput,
            onFinish: () => {
                removingAvatar = false;
            },
        });
    };

    onDestroy(clearAvatarPreview);
</script>

<Form
    {...ProfileController.update.form()}
    class="flex flex-col gap-6"
    options={{ preserveScroll: true }}
    onSuccess={profileSaved}
>
    {#snippet children({ errors, processing })}
        <div class="flex flex-col items-center gap-3">
            <Avatar class="size-32 overflow-hidden">
                {#if avatarSource}
                    <AvatarImage src={avatarSource} alt={user.name} />
                {/if}
                <AvatarFallback class="text-3xl">
                    {getInitials(user.name)}
                </AvatarFallback>
            </Avatar>

            <input
                bind:this={avatarInput}
                id={fieldId('avatar')}
                name="avatar"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                class="sr-only"
                aria-invalid={Boolean(errors.avatar)}
                onchange={previewAvatar}
            />

            <div class="flex flex-wrap justify-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    onclick={() => avatarInput?.click()}
                >
                    <Camera data-icon="inline-start" />
                    {t('Change avatar')}
                </Button>
                {#if user.avatar}
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={removingAvatar}
                        onclick={removeAvatar}
                    >
                        {#if removingAvatar}
                            <Spinner data-icon="inline-start" />
                        {:else}
                            <Trash2 data-icon="inline-start" />
                        {/if}
                        {t('Remove avatar')}
                    </Button>
                {/if}
            </div>
            <p class="text-xs text-muted-foreground">
                {t('PNG, JPG or WebP up to 2 MB.')}
            </p>
            <InputError message={errors.avatar} />
        </div>

        <div class="flex flex-col gap-2">
            <Label for={fieldId('name')}>{t('Name')}</Label>
            <Input
                id={fieldId('name')}
                name="name"
                value={user.name}
                required
                autocomplete="name"
                maxlength={255}
                aria-invalid={Boolean(errors.name)}
                placeholder={t('Full name')}
            />
            <InputError message={errors.name} />
        </div>

        <div class="flex flex-col gap-2">
            <Label for={fieldId('job-title')}>{t('Job title')}</Label>
            <Input
                id={fieldId('job-title')}
                name="job_title"
                value={user.job_title ?? ''}
                autocomplete="organization-title"
                maxlength={255}
                aria-invalid={Boolean(errors.job_title)}
                placeholder={t('Your role or title')}
            />
            <InputError message={errors.job_title} />
        </div>

        <div class="flex flex-col gap-2">
            <Label for={fieldId('location')}>{t('Location')}</Label>
            <Input
                id={fieldId('location')}
                name="location"
                value={user.location ?? ''}
                maxlength={255}
                aria-invalid={Boolean(errors.location)}
                placeholder={t('Where you work')}
            />
            <InputError message={errors.location} />
        </div>

        <div class="flex flex-col gap-2">
            <Label for={fieldId('status')}>{t('Current status')}</Label>
            <Input
                id={fieldId('status')}
                name="status"
                value={user.status ?? ''}
                maxlength={255}
                aria-invalid={Boolean(errors.status)}
                placeholder={t('What are you working on?')}
            />
            <InputError message={errors.status} />
        </div>

        <div class="flex flex-col gap-2">
            <Label for={fieldId('email')}>{t('Email address')}</Label>
            <Input
                id={fieldId('email')}
                type="email"
                name="email"
                value={user.email}
                required
                autocomplete="username"
                maxlength={255}
                aria-invalid={Boolean(errors.email)}
                placeholder={t('Email address')}
            />
            <InputError message={errors.email} />
        </div>

        {#if !user.email_verified_at}
            <div class="flex flex-col gap-2 text-sm text-muted-foreground">
                <p>
                    {t('Your email address is unverified.')}
                    <TextLink href={send()} as="button">
                        {t('Click here to re-send the verification email.')}
                    </TextLink>
                </p>

                {#if page.props.status === 'verification-link-sent'}
                    <p class="font-medium text-foreground">
                        {t(
                            'A new verification link has been sent to your email address.',
                        )}
                    </p>
                {/if}
            </div>
        {/if}

        <Button
            type="submit"
            class="w-full"
            disabled={processing || removingAvatar}
            data-test="update-profile-button"
        >
            {#if processing}
                <Spinner data-icon="inline-start" />
            {/if}
            {t('Save changes')}
        </Button>
    {/snippet}
</Form>
