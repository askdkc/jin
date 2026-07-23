<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import ArrowRight from 'lucide-svelte/icons/arrow-right';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import ChartColumn from 'lucide-svelte/icons/chart-column';
    import CheckCircle from 'lucide-svelte/icons/circle-check';
    import FileText from 'lucide-svelte/icons/file-text';
    import FolderKanban from 'lucide-svelte/icons/folder-kanban';
    import MessageCircle from 'lucide-svelte/icons/message-circle';
    import ShieldCheck from 'lucide-svelte/icons/shield-check';
    import AppHead from '@/components/AppHead.svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import { toUrl } from '@/lib/utils';
    import { dashboard, login } from '@/routes';
    import type { Team } from '@/types';

    const auth = $derived(page.props.auth);
    const currentTeam = $derived(page.props.currentTeam as Team | null);
    const canOpenJin = $derived(Boolean(auth.user && currentTeam));
    const primaryHref = $derived(
        canOpenJin && currentTeam ? dashboard(currentTeam.slug) : login(),
    );
    const primaryLabel = $derived(canOpenJin ? 'JINを開く' : 'ログイン');

    const features = [
        {
            icon: FolderKanban,
            label: '状況をそろえる',
            title: '本陣・最近のみんなの行動',
            description:
                '掲示板、任務、行程、資料、会話を一つの陣に集約。最近のみんなの行動から、仕事の流れもすぐに追えます。',
        },
        {
            icon: CheckCircle,
            label: '仕事を前へ動かす',
            title: '任務・作戦盤・振り返り',
            description:
                '担当者と期限を明確にし、一覧とカードの両方で進行を整理。定期チェックインで報告の抜けも防ぎます。',
        },
        {
            icon: CalendarDays,
            label: '予定を見渡す',
            title: '行程・布陣',
            description:
                '日・週・月・ガント表示を切り替え、複数プロジェクトの予定と期間を同じ時間軸で確認できます。',
        },
        {
            icon: MessageCircle,
            label: '判断を止めない',
            title: '会話・コメント・通知',
            description:
                'Laravel Reverbによるチャット、コメント、通知を用意。離れたメンバーとも、その場で相談して決められます。',
        },
        {
            icon: FileText,
            label: '知識を残す',
            title: '資料庫',
            description:
                'ファイルをドラッグ＆ドロップで共有。PDF、Office、CSVはダウンロードせず、その場で内容を確認できます。',
        },
        {
            icon: ChartColumn,
            label: '全体を把握する',
            title: '戦況・稼働・管理所',
            description:
                '横断レポートと工数記録で全体を把握。管理者はプロジェクトと利用者の権限をまとめて管理できます。',
        },
    ];

    const screens = [
        {
            image: '/images/landing/project.webp?v=20260724-dummy',
            eyebrow: 'PROJECT HOME',
            title: '必要なものが、最初から同じ場所にある',
            description:
                'プロジェクトを開けば、直近の行動と各ツールの状況が一画面に並びます。情報を探すために複数サービスを行き来する必要はありません。',
            points: [
                '最近の行動を3件まで表示',
                '主要ツールを一覧で確認',
                'スマートフォンでも同じ情報構造',
            ],
            imageOrder: '',
        },
        {
            image: '/images/landing/calendar.webp?v=20260724-dummy',
            eyebrow: 'CALENDAR & LINEUP',
            title: '今日の予定から数か月先の陣形まで',
            description:
                '日・週・月の予定とガント表示を切り替えられます。個々の予定だけでなく、複数プロジェクトが重なる時期も見逃しません。',
            points: [
                '日・週・月・ガント表示',
                '全プロジェクトを横断',
                '外部カレンダーから購読可能',
            ],
            imageOrder: 'lg:order-2',
        },
        {
            image: '/images/landing/materials.webp?v=20260724-dummy',
            eyebrow: 'MATERIALS',
            title: '資料は置くだけ。閲覧まで迷わせない',
            description:
                '資料庫へドラッグ＆ドロップし、保存先を選ぶだけで共有完了。プレビュー対応文書はブラウザ内でそのまま確認できます。',
            points: [
                'ドラッグ＆ドロップ対応',
                'フォルダーで整理',
                'PDF・Office・CSVをプレビュー',
            ],
            imageOrder: '',
        },
    ];
</script>

<AppHead title="JIN（陣）">
    <meta
        name="description"
        content="JINは、プロジェクト、予定、会話、資料を一つの場所に集める社内向けグループウェアです。"
    />
    <meta name="theme-color" content="#071017" />
</AppHead>

<div
    class="min-h-screen overflow-x-hidden bg-[#071017] text-slate-100 selection:bg-[#b44332] selection:text-white"
>
    <a
        href="#main-content"
        class="sr-only z-[100] rounded-md bg-white px-4 py-2 text-slate-950 focus:not-sr-only focus:fixed focus:top-4 focus:left-4"
    >
        本文へ移動
    </a>

    <header
        class="sticky top-0 z-50 border-b border-white/10 bg-[#071017]/85 backdrop-blur-xl"
    >
        <div
            class="mx-auto flex h-18 max-w-7xl items-center justify-between gap-4 px-5 sm:px-8 lg:px-10"
        >
            <a
                href="#main-content"
                class="flex items-center gap-3 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-amber-300"
                aria-label="陣（JIN）トップ"
            >
                <AppLogoIcon class="size-11 shrink-0" />
                <span class="leading-none">
                    <span
                        class="block text-lg font-black tracking-[0.16em] text-white"
                        >陣（JIN）</span
                    >
                    <span
                        class="mt-1 block text-[10px] font-semibold tracking-[0.22em] text-amber-200/70"
                        >TEAM WORKSPACE</span
                    >
                </span>
            </a>

            <nav
                class="hidden items-center gap-8 text-sm font-medium text-slate-300 md:flex"
                aria-label="ページ内メニュー"
            >
                <a
                    href="#features"
                    class="rounded-sm outline-none transition-colors hover:text-white focus-visible:ring-2 focus-visible:ring-amber-300 motion-safe:duration-200"
                    >機能</a
                >
                <a
                    href="#workflow"
                    class="rounded-sm outline-none transition-colors hover:text-white focus-visible:ring-2 focus-visible:ring-amber-300 motion-safe:duration-200"
                    >使いやすさ</a
                >
                <a
                    href="#screens"
                    class="rounded-sm outline-none transition-colors hover:text-white focus-visible:ring-2 focus-visible:ring-amber-300 motion-safe:duration-200"
                    >画面</a
                >
            </nav>

            <Link
                href={toUrl(primaryHref)}
                prefetch
                data-test="welcome-primary-action"
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-amber-300/50 bg-amber-200 px-5 text-sm font-bold text-[#142029] shadow-[0_8px_24px_rgba(218,176,77,0.16)] outline-none transition hover:-translate-y-0.5 hover:bg-amber-100 focus-visible:ring-2 focus-visible:ring-amber-300 focus-visible:ring-offset-2 focus-visible:ring-offset-[#071017] motion-safe:duration-200"
            >
                {primaryLabel}
                <ArrowRight class="size-4" aria-hidden="true" />
            </Link>
        </div>
    </header>

    <main id="main-content">
        <section
            class="relative border-b border-white/10 bg-[radial-gradient(circle_at_12%_8%,rgba(177,62,45,0.24),transparent_28%),radial-gradient(circle_at_86%_20%,rgba(218,176,77,0.14),transparent_26%)]"
            aria-labelledby="hero-heading"
        >
            <div
                class="pointer-events-none absolute inset-0 opacity-20 [background-image:linear-gradient(rgba(255,255,255,0.04)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.04)_1px,transparent_1px)] [background-size:72px_72px]"
                aria-hidden="true"
            ></div>
            <div
                class="relative mx-auto grid max-w-7xl gap-14 px-5 pt-20 pb-16 sm:px-8 sm:pt-28 sm:pb-24 lg:grid-cols-[0.82fr_1.18fr] lg:items-center lg:px-10 lg:pt-32"
            >
                <div>
                    <p
                        class="mb-6 inline-flex items-center gap-2 rounded-full border border-[#d7b45d]/30 bg-[#d7b45d]/8 px-4 py-2 text-xs font-bold tracking-[0.18em] text-amber-200"
                    >
                        <span class="size-1.5 rounded-full bg-[#c24f3a]"></span>
                        社内の仕事を一つの陣に
                    </p>
                    <h1
                        id="hero-heading"
                        class="max-w-3xl text-4xl leading-[1.12] font-black tracking-[-0.045em] text-balance text-white sm:text-6xl lg:text-7xl"
                    >
                        チームの仕事を、<br />ひとつの陣に
                    </h1>
                    <p
                        class="mt-7 max-w-xl text-base leading-8 text-pretty text-slate-300 sm:text-lg"
                    >
                        プロジェクト、予定、会話、資料を一か所へ。JINは、複数のチームが同じ状況を見ながら協調して動くための社内グループウェアです。
                    </p>
                    <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                        <Link
                            href={toUrl(primaryHref)}
                            prefetch
                            class="inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-[#b44332] px-6 text-sm font-bold text-white shadow-[0_14px_40px_rgba(180,67,50,0.28)] outline-none transition hover:-translate-y-0.5 hover:bg-[#c24f3a] focus-visible:ring-2 focus-visible:ring-[#e07b69] focus-visible:ring-offset-2 focus-visible:ring-offset-[#071017] motion-safe:duration-200"
                        >
                            {primaryLabel}
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                        <a
                            href="#features"
                            class="inline-flex min-h-12 items-center justify-center rounded-full border border-white/15 bg-white/5 px-6 text-sm font-bold text-slate-100 outline-none transition hover:border-white/30 hover:bg-white/10 focus-visible:ring-2 focus-visible:ring-amber-300 motion-safe:duration-200"
                        >
                            できることを見る
                        </a>
                    </div>
                    <dl
                        class="mt-11 grid max-w-xl grid-cols-1 gap-5 border-t border-white/10 pt-6 text-sm sm:grid-cols-3"
                    >
                        <div>
                            <dt class="font-bold text-white">見つけやすい</dt>
                            <dd class="mt-1 text-slate-400">
                                一つのプロジェクト画面
                            </dd>
                        </div>
                        <div>
                            <dt class="font-bold text-white">伝わりやすい</dt>
                            <dd class="mt-1 text-slate-400">
                                行動と会話を時系列で
                            </dd>
                        </div>
                        <div>
                            <dt class="font-bold text-white">場所を選ばない</dt>
                            <dd class="mt-1 text-slate-400">
                                スマートフォンにも対応
                            </dd>
                        </div>
                    </dl>
                </div>

                <figure class="relative mx-auto w-full max-w-[780px]">
                    <div
                        class="absolute -inset-5 rounded-[2rem] bg-[linear-gradient(135deg,rgba(180,67,50,0.22),rgba(215,180,93,0.12),transparent)] blur-2xl"
                        aria-hidden="true"
                    ></div>
                    <div
                        class="relative overflow-hidden rounded-2xl border border-white/15 bg-[#0d1820] p-2 shadow-[0_30px_90px_rgba(0,0,0,0.45)] sm:p-3"
                    >
                        <div
                            class="mb-2 flex items-center gap-1.5 px-2 py-1 sm:mb-3"
                            aria-hidden="true"
                        >
                            <span class="size-2 rounded-full bg-[#b44332]"
                            ></span>
                            <span class="size-2 rounded-full bg-[#d7b45d]"
                            ></span>
                            <span class="size-2 rounded-full bg-emerald-600"
                            ></span>
                            <span
                                class="ml-2 text-[10px] font-semibold tracking-[0.16em] text-slate-500"
                                >JIN / DASHBOARD</span
                            >
                        </div>
                        <img
                            src="/images/landing/dashboard.webp?v=20260724-dummy"
                            alt="JINのダッシュボード。プロジェクト一覧と最近の更新を一画面で確認できる"
                            width="1440"
                            height="900"
                            fetchpriority="high"
                            decoding="async"
                            class="aspect-[8/5] w-full rounded-lg object-cover object-top"
                        />
                    </div>
                    <figcaption class="sr-only">
                        匿名デモデータを使用したJINの実際のダッシュボード画面
                    </figcaption>
                </figure>
            </div>
        </section>

        <section
            id="features"
            class="scroll-mt-24 border-b border-white/10 bg-[#0a141c] py-20 sm:py-28"
            aria-labelledby="features-heading"
        >
            <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                <div class="max-w-3xl">
                    <p
                        class="text-xs font-bold tracking-[0.22em] text-[#d7b45d]"
                    >
                        ONE PLACE, ONE TEAM
                    </p>
                    <h2
                        id="features-heading"
                        class="mt-4 text-3xl font-black tracking-[-0.035em] text-white sm:text-5xl"
                    >
                        必要な情報が、ひとつに集まる<br
                        />迷わず判断し、すぐ動ける
                    </h2>
                    <p
                        class="mt-6 max-w-2xl text-base leading-8 text-slate-400"
                    >
                        必要な機能はプロジェクトごとにまとまり、更新はチーム全体へ伝わります。使う人は「次にどこを見るか」で迷いません。
                    </p>
                </div>

                <div class="mt-12 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {#each features as feature (feature.title)}
                        {@const Icon = feature.icon}
                        <article
                            class="group rounded-2xl border border-white/10 bg-white/[0.035] p-6 transition hover:-translate-y-1 hover:border-[#d7b45d]/35 hover:bg-white/[0.06] motion-safe:duration-300 sm:p-7"
                        >
                            <div
                                class="flex size-11 items-center justify-center rounded-xl border border-[#d7b45d]/25 bg-[#d7b45d]/10 text-amber-200"
                            >
                                <Icon class="size-5" aria-hidden="true" />
                            </div>
                            <p
                                class="mt-6 text-xs font-bold tracking-[0.16em] text-[#c96b5c]"
                            >
                                {feature.label}
                            </p>
                            <h3 class="mt-2 text-xl font-black text-white">
                                {feature.title}
                            </h3>
                            <p class="mt-4 text-sm leading-7 text-slate-400">
                                {feature.description}
                            </p>
                        </article>
                    {/each}
                </div>
            </div>
        </section>

        <section
            id="workflow"
            class="scroll-mt-24 border-b border-white/10 bg-[#071017] py-20 sm:py-28"
            aria-labelledby="workflow-heading"
        >
            <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                <div
                    class="grid gap-12 lg:grid-cols-[0.72fr_1.28fr] lg:items-start"
                >
                    <div class="lg:sticky lg:top-28">
                        <p
                            class="text-xs font-bold tracking-[0.22em] text-[#d7b45d]"
                        >
                            SIMPLE WORKFLOW
                        </p>
                        <h2
                            id="workflow-heading"
                            class="mt-4 text-3xl font-black tracking-[-0.035em] text-white sm:text-5xl"
                        >
                            覚える順番は、<br />たった三つ
                        </h2>
                        <p
                            class="mt-6 max-w-lg text-base leading-8 text-slate-400"
                        >
                            専門的な設定を覚えなくても、プロジェクトを開けば仕事を始められる設計です。
                        </p>
                    </div>

                    <ol class="grid gap-4">
                        <li
                            class="grid gap-5 rounded-2xl border border-white/10 bg-[#0d1921] p-6 sm:grid-cols-[72px_1fr] sm:p-8"
                        >
                            <span
                                class="text-4xl font-black tracking-[-0.06em] text-[#b44332]"
                                >01</span
                            >
                            <div>
                                <h3 class="text-xl font-black text-white">
                                    プロジェクトを開く
                                </h3>
                                <p class="mt-3 leading-7 text-slate-400">
                                    参加メンバー、最近の動き、利用するツールが最初の画面にまとまっています。
                                </p>
                            </div>
                        </li>
                        <li
                            class="grid gap-5 rounded-2xl border border-white/10 bg-[#0d1921] p-6 sm:grid-cols-[72px_1fr] sm:p-8"
                        >
                            <span
                                class="text-4xl font-black tracking-[-0.06em] text-[#d7b45d]"
                                >02</span
                            >
                            <div>
                                <h3 class="text-xl font-black text-white">
                                    仕事を記録する
                                </h3>
                                <p class="mt-3 leading-7 text-slate-400">
                                    任務、行程、掲示板、資料庫から目的に合う場所を選び、担当と期限を残します。
                                </p>
                            </div>
                        </li>
                        <li
                            class="grid gap-5 rounded-2xl border border-white/10 bg-[#0d1921] p-6 sm:grid-cols-[72px_1fr] sm:p-8"
                        >
                            <span
                                class="text-4xl font-black tracking-[-0.06em] text-emerald-500"
                                >03</span
                            >
                            <div>
                                <h3 class="text-xl font-black text-white">
                                    更新を見て、次に動く
                                </h3>
                                <p class="mt-3 leading-7 text-slate-400">
                                    最近のみんなの行動と通知から変化を把握し、コメントやチャットで次の判断につなげます。
                                </p>
                            </div>
                        </li>
                    </ol>
                </div>
            </div>
        </section>

        <section
            id="screens"
            class="scroll-mt-24 border-b border-white/10 bg-[#0a141c] py-20 sm:py-28"
            aria-labelledby="screens-heading"
        >
            <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                <div class="mx-auto max-w-3xl text-center">
                    <p
                        class="text-xs font-bold tracking-[0.22em] text-[#d7b45d]"
                    >
                        ACTUAL SCREENS
                    </p>
                    <h2
                        id="screens-heading"
                        class="mt-4 text-3xl font-black tracking-[-0.035em] text-white sm:text-5xl"
                    >
                        毎日使う画面を、そのまま
                    </h2>
                    <p class="mt-6 text-base leading-8 text-slate-400">
                        以下は匿名デモデータを使ったJINの実画面です。デスクトップでもスマートフォンでも、同じ情報へ迷わずたどり着けます。
                    </p>
                </div>

                <div class="mt-16 grid gap-20 sm:mt-20 sm:gap-28">
                    {#each screens as screen (screen.title)}
                        <article
                            class="grid items-center gap-8 lg:grid-cols-[1.12fr_0.88fr] lg:gap-14"
                        >
                            <figure class={`min-w-0 ${screen.imageOrder}`}>
                                <div
                                    class="overflow-hidden rounded-2xl border border-white/15 bg-[#071017] p-2 shadow-[0_24px_70px_rgba(0,0,0,0.35)] sm:p-3"
                                >
                                    <img
                                        src={screen.image}
                                        alt={screen.title}
                                        width="1440"
                                        height="900"
                                        loading="lazy"
                                        decoding="async"
                                        class="aspect-[8/5] w-full rounded-lg object-cover object-top"
                                    />
                                </div>
                                <figcaption class="sr-only">
                                    匿名デモデータを使用したJINの{screen.title}画面
                                </figcaption>
                            </figure>
                            <div>
                                <p
                                    class="text-xs font-bold tracking-[0.2em] text-[#c96b5c]"
                                >
                                    {screen.eyebrow}
                                </p>
                                <h3
                                    class="mt-4 text-2xl leading-tight font-black tracking-[-0.025em] text-white sm:text-4xl"
                                >
                                    {screen.title}
                                </h3>
                                <p class="mt-5 leading-8 text-slate-400">
                                    {screen.description}
                                </p>
                                <ul class="mt-6 grid gap-3">
                                    {#each screen.points as point (point)}
                                        <li
                                            class="flex items-center gap-3 text-sm font-medium text-slate-200"
                                        >
                                            <span
                                                class="flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-400"
                                            >
                                                <CheckCircle
                                                    class="size-3.5"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                            {point}
                                        </li>
                                    {/each}
                                </ul>
                            </div>
                        </article>
                    {/each}
                </div>
            </div>
        </section>

        <section
            class="bg-[#071017] py-20 sm:py-28"
            aria-labelledby="access-heading"
        >
            <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
                <div
                    class="relative overflow-hidden rounded-3xl border border-[#d7b45d]/20 bg-[linear-gradient(120deg,rgba(180,67,50,0.16),rgba(215,180,93,0.08),rgba(255,255,255,0.025))] p-7 sm:p-12 lg:p-16"
                >
                    <div
                        class="pointer-events-none absolute top-0 right-0 size-72 translate-x-1/3 -translate-y-1/3 rounded-full border border-[#d7b45d]/15"
                        aria-hidden="true"
                    ></div>
                    <div
                        class="relative grid gap-10 lg:grid-cols-[0.82fr_1.18fr] lg:items-center"
                    >
                        <div
                            class="flex size-16 items-center justify-center rounded-2xl border border-[#d7b45d]/25 bg-[#d7b45d]/10 text-amber-200"
                        >
                            <ShieldCheck class="size-8" aria-hidden="true" />
                        </div>
                        <div>
                            <p
                                class="text-xs font-bold tracking-[0.2em] text-[#d7b45d]"
                            >
                                ACCESS CONTROL
                            </p>
                            <h2
                                id="access-heading"
                                class="mt-4 text-3xl font-black tracking-[-0.035em] text-white sm:text-5xl"
                            >
                                必要な人だけが、必要な陣へ
                            </h2>
                            <p class="mt-6 max-w-3xl leading-8 text-slate-300">
                                管理所からプロジェクトと利用者を管理し、公開範囲はプロジェクト単位・ユーザー単位で設定できます。オンプレミス環境の運用方針に合わせて、社内の情報を社内で管理できます。
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="mt-20 flex flex-col items-start justify-between gap-8 border-t border-white/10 pt-10 sm:flex-row sm:items-center"
                >
                    <div>
                        <p class="text-2xl font-black text-white">
                            準備ができたら、JINへ
                        </p>
                        <p class="mt-2 text-sm text-slate-400">
                            いつものアカウントでログインしてください。
                        </p>
                    </div>
                    <Link
                        href={toUrl(primaryHref)}
                        prefetch
                        class="inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-[#b44332] px-7 text-sm font-bold text-white outline-none transition hover:-translate-y-0.5 hover:bg-[#c24f3a] focus-visible:ring-2 focus-visible:ring-[#e07b69] focus-visible:ring-offset-2 focus-visible:ring-offset-[#071017] motion-safe:duration-200"
                    >
                        {primaryLabel}
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-white/10 bg-[#050c11]">
        <div
            class="mx-auto flex max-w-7xl flex-col gap-5 px-5 py-8 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-8 lg:px-10"
        >
            <div class="flex items-center gap-3">
                <AppLogoIcon class="size-9" />
                <span class="font-bold tracking-[0.16em] text-slate-300"
                    >JIN</span
                >
            </div>
            <p>チームの仕事を、ひとつの陣に</p>
        </div>
    </footer>
</div>
