<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import LoaderCircle from 'lucide-svelte/icons/loader-circle';
    import Mic from 'lucide-svelte/icons/mic';
    import Square from 'lucide-svelte/icons/square';
    import { onMount } from 'svelte';
    import VoiceNoteController from '@/actions/App/Http/Controllers/VoiceNoteController';
    import { t } from '@/lib/i18n';

    let {
        onUploaded,
        compact = false,
    }: {
        onUploaded: (url: string) => void;
        compact?: boolean;
    } = $props();

    let mediaRecorder: MediaRecorder | null = null;
    let mediaStream: MediaStream | null = null;
    let audioChunks: Blob[] = [];
    let recording = $state(false);
    let uploading = $state(false);
    let error = $state('');

    const stopMediaStream = (): void => {
        mediaStream?.getTracks().forEach((track) => track.stop());
        mediaStream = null;
    };

    const upload = async (blob: Blob): Promise<void> => {
        const team = page.props.currentTeam;

        if (!team) {
            error = t('Voice notes require a team workspace.');

            return;
        }

        uploading = true;
        error = '';

        try {
            const formData = new FormData();
            const extension = blob.type.includes('ogg') ? 'ogg' : 'webm';
            formData.append('audio', blob, `voice-note.${extension}`);
            const csrfToken = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
            const response = await fetch(
                VoiceNoteController.store.url(team.slug),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                    },
                    body: formData,
                },
            );

            if (!response.ok) {
                throw new Error(`Voice note upload failed: ${response.status}`);
            }

            const payload = (await response.json()) as { url: string };
            onUploaded(payload.url);
        } catch {
            error = t('Could not save the voice note.');
        } finally {
            uploading = false;
        }
    };

    const startRecording = async (): Promise<void> => {
        error = '';

        if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
            error = t('Voice recording is not supported by this browser.');

            return;
        }

        try {
            mediaStream = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });
            const preferredMimeType = MediaRecorder.isTypeSupported(
                'audio/webm;codecs=opus',
            )
                ? 'audio/webm;codecs=opus'
                : '';
            mediaRecorder = new MediaRecorder(
                mediaStream,
                preferredMimeType ? { mimeType: preferredMimeType } : undefined,
            );
            audioChunks = [];
            mediaRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    audioChunks.push(event.data);
                }
            };
            mediaRecorder.onstop = () => {
                const blob = new Blob(audioChunks, {
                    type: mediaRecorder?.mimeType || 'audio/webm',
                });
                stopMediaStream();

                if (blob.size > 0) {
                    void upload(blob);
                }
            };
            mediaRecorder.start();
            recording = true;
        } catch {
            stopMediaStream();
            error = t('Microphone access was denied.');
        }
    };

    const stopRecording = (): void => {
        if (mediaRecorder?.state === 'recording') {
            mediaRecorder.stop();
        }

        recording = false;
    };

    onMount(() => () => {
        if (mediaRecorder?.state === 'recording') {
            mediaRecorder.stop();
        }

        stopMediaStream();
    });
</script>

<div class="relative shrink-0">
    <button
        type="button"
        class={recording
            ? compact
                ? 'inline-flex size-10 items-center justify-center rounded-lg border border-red-400 bg-red-50 text-red-700 dark:bg-red-950/30 dark:text-red-200'
                : 'inline-flex min-h-9 items-center gap-2 rounded-md border border-red-400 bg-red-50 px-3 text-xs font-semibold text-red-700 dark:bg-red-950/30 dark:text-red-200'
            : compact
              ? 'inline-flex size-10 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'
              : 'inline-flex min-h-9 items-center gap-2 rounded-md border bg-background px-3 text-xs font-semibold hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'}
        disabled={uploading}
        aria-label={recording ? t('Stop recording') : t('Voice note')}
        title={error || (recording ? t('Stop recording') : t('Voice note'))}
        onclick={() => (recording ? stopRecording() : void startRecording())}
    >
        {#if uploading}
            <LoaderCircle class="size-4 animate-spin" />
            {#if !compact}{t('Saving')}{/if}
        {:else if recording}
            <Square class="size-4 fill-current" />
            {#if !compact}{t('Stop recording')}{/if}
        {:else}
            <Mic class="size-4" />
            {#if !compact}{t('Voice note')}{/if}
        {/if}
    </button>
    {#if error}
        <p
            class="absolute right-0 bottom-full z-20 mb-2 w-64 rounded-md border bg-popover p-2 text-xs font-medium text-destructive shadow-md"
            role="alert"
        >
            {error}
        </p>
    {/if}
</div>
