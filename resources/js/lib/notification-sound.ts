type AudioContextConstructor = new () => AudioContext;

type WindowWithWebkitAudio = Window &
    typeof globalThis & {
        webkitAudioContext?: AudioContextConstructor;
    };

type ChimeTone = {
    delay: number;
    duration: number;
    frequency: number;
    volume: number;
};

export const notificationChime: readonly ChimeTone[] = [
    { frequency: 587.33, delay: 0, duration: 0.16, volume: 0.055 },
    { frequency: 880, delay: 0.11, duration: 0.22, volume: 0.045 },
];

let audioContext: AudioContext | null = null;
let removeUnlockListeners: (() => void) | null = null;

const getAudioContext = (): AudioContext | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    const browserWindow = window as WindowWithWebkitAudio;
    const Context =
        browserWindow.AudioContext ?? browserWindow.webkitAudioContext;

    if (!Context) {
        return null;
    }

    audioContext ??= new Context();

    return audioContext;
};

const playTone = (context: AudioContext, tone: ChimeTone): void => {
    const startsAt = context.currentTime + tone.delay;
    const endsAt = startsAt + tone.duration;
    const oscillator = context.createOscillator();
    const gain = context.createGain();

    oscillator.type = 'sine';
    oscillator.frequency.setValueAtTime(tone.frequency, startsAt);
    gain.gain.setValueAtTime(0.0001, startsAt);
    gain.gain.exponentialRampToValueAtTime(tone.volume, startsAt + 0.015);
    gain.gain.exponentialRampToValueAtTime(0.0001, endsAt);
    oscillator.connect(gain);
    gain.connect(context.destination);
    oscillator.addEventListener(
        'ended',
        () => {
            oscillator.disconnect();
            gain.disconnect();
        },
        { once: true },
    );
    oscillator.start(startsAt);
    oscillator.stop(endsAt);
};

export const prepareNotificationSound = (): (() => void) => {
    if (typeof window === 'undefined' || audioContext?.state === 'running') {
        return () => {};
    }

    if (removeUnlockListeners) {
        return removeUnlockListeners;
    }

    const unlock = (): void => {
        const context = getAudioContext();

        if (context?.state === 'suspended') {
            void context.resume().catch(() => undefined);
        }

        removeUnlockListeners?.();
    };
    const remove = (): void => {
        window.removeEventListener('pointerdown', unlock);
        window.removeEventListener('keydown', unlock);
        removeUnlockListeners = null;
    };

    removeUnlockListeners = remove;
    window.addEventListener('pointerdown', unlock, {
        once: true,
        passive: true,
    });
    window.addEventListener('keydown', unlock, { once: true });

    return remove;
};

export const playNotificationSound = async (): Promise<void> => {
    const context = getAudioContext();

    if (!context) {
        return;
    }

    try {
        if (context.state === 'suspended') {
            await context.resume();
        }

        if (context.state !== 'running') {
            return;
        }

        notificationChime.forEach((tone) => playTone(context, tone));
    } catch {
        // Browsers can still reject audio when the page has never been activated.
    }
};
