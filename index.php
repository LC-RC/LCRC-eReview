<?php
require_once 'session_config.php';
require_once 'auth.php';
require_once __DIR__ . '/includes/registration_school_options.php';

$schoolDropdownOptions = ereview_get_registration_school_dropdown_options($conn);

if (isLoggedIn() && verifySession()) {
    header('Location: ' . dashboardUrlForRole(getCurrentUserRole()));
    exit;
}
$pageTitle = 'Home';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <?php require_once __DIR__ . '/includes/head_public.php'; ?>
</head>
<body class="min-h-screen bg-gray-50 font-sans antialiased"
      x-data="{
        openModal: '<?php echo isset($_SESSION['open_modal']) ? h($_SESSION['open_modal']) : ''; ?>',
        showLoginPass: false,
        showRegisterPass: false,
        otherSchool: '',
        mobileMenuOpen: false
      }"
      x-init="if (openModal) { $nextTick(() => { $dispatch('open-modal', openModal); }); } openModal = ''"
      @keydown.escape.window="mobileMenuOpen = false">
<div class="min-h-screen flex flex-col">
  <!-- Top Navigation Bar (glassy) -->
  <nav class="sticky top-0 z-40 border-b border-white/20 bg-white/60 backdrop-blur-xl shadow-[0_1px_0_0_rgba(255,255,255,0.5)_inset,0_4px_20px_-2px_rgba(0,0,0,0.08)]">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-20 md:h-24">
        <!-- Left: Logo + Brand -->
        <a href="#home" class="flex items-center gap-1 shrink-0 group">
          <span class="relative flex shrink-0 items-center" style="height:48px;width:auto;min-width:80px;max-width:130px">
            <img src="image%20assets/lms-logo-top.png" alt="LCRC-eLMS" class="select-none" style="height:48px;width:auto;max-width:130px;object-fit:contain;object-position:center;display:block" loading="eager">
          </span>
          <span class="text-xl font-extrabold tracking-tight">
            <span class="text-accent-blue">LCRC</span><span class="text-gray-400">-</span><span class="text-accent-orange">eLMS</span>
          </span>
        </a>

        <!-- Center: Nav links (desktop) - alternating blue/orange hover per link -->
        <div class="hidden md:flex items-center gap-0.5">
          <a href="#home" class="group/nav relative px-4 py-3 rounded-xl text-gray-600 font-bold tracking-wide transition-all duration-300 hover:text-accent-blue hover:bg-accent-blue/10 hover:shadow-[0_2px_12px_-2px_rgba(31,88,195,0.2)] hover:-translate-y-0.5 active:scale-[0.98]">
            <span class="relative z-10">Home</span>
            <span class="absolute inset-x-2 bottom-2 h-0.5 scale-x-0 rounded-full bg-accent-blue transition-transform duration-300 ease-out group-hover/nav:scale-x-100" style="transform-origin:center"></span>
          </a>
          <a href="#free-samples" class="group/nav relative px-4 py-3 rounded-xl text-gray-600 font-bold tracking-wide transition-all duration-300 hover:text-accent-orange hover:bg-accent-orange/10 hover:shadow-[0_2px_12px_-2px_rgba(245,158,11,0.2)] hover:-translate-y-0.5 active:scale-[0.98]">
            <span class="relative z-10">Free Samples</span>
            <span class="absolute inset-x-2 bottom-2 h-0.5 scale-x-0 rounded-full bg-accent-orange transition-transform duration-300 ease-out group-hover/nav:scale-x-100" style="transform-origin:center"></span>
          </a>
          <a href="#packages" class="group/nav relative px-4 py-3 rounded-xl text-gray-600 font-bold tracking-wide transition-all duration-300 hover:text-accent-blue hover:bg-accent-blue/10 hover:shadow-[0_2px_12px_-2px_rgba(31,88,195,0.2)] hover:-translate-y-0.5 active:scale-[0.98]">
            <span class="relative z-10">Packages</span>
            <span class="absolute inset-x-2 bottom-2 h-0.5 scale-x-0 rounded-full bg-accent-blue transition-transform duration-300 ease-out group-hover/nav:scale-x-100" style="transform-origin:center"></span>
          </a>
          <a href="#about" class="group/nav relative px-4 py-3 rounded-xl text-gray-600 font-bold tracking-wide transition-all duration-300 hover:text-accent-orange hover:bg-accent-orange/10 hover:shadow-[0_2px_12px_-2px_rgba(245,158,11,0.2)] hover:-translate-y-0.5 active:scale-[0.98]">
            <span class="relative z-10">About</span>
            <span class="absolute inset-x-2 bottom-2 h-0.5 scale-x-0 rounded-full bg-accent-orange transition-transform duration-300 ease-out group-hover/nav:scale-x-100" style="transform-origin:center"></span>
          </a>
          <a href="#faqs" class="group/nav relative px-4 py-3 rounded-xl text-gray-600 font-bold tracking-wide transition-all duration-300 hover:text-accent-blue hover:bg-accent-blue/10 hover:shadow-[0_2px_12px_-2px_rgba(31,88,195,0.2)] hover:-translate-y-0.5 active:scale-[0.98]">
            <span class="relative z-10">FAQs</span>
            <span class="absolute inset-x-2 bottom-2 h-0.5 scale-x-0 rounded-full bg-accent-blue transition-transform duration-300 ease-out group-hover/nav:scale-x-100" style="transform-origin:center"></span>
          </a>
        </div>

        <!-- Right: Login + Register -->
        <div class="hidden md:flex items-center gap-3">
          <a href="login.php" class="group relative px-6 py-2.5 rounded-xl font-semibold text-accent-blue border-2 border-accent-blue bg-transparent hover:bg-accent-blue hover:text-white hover:shadow-[0_8px_25px_-5px_rgba(31,88,195,0.25)] hover:-translate-y-0.5 active:translate-y-0 focus:outline-none focus:ring-2 focus:ring-accent-blue/40 focus:ring-offset-2 transition-all duration-300 ease-out">
            <span class="relative z-10">Login</span>
          </a>
          <a href="registration.php" class="group relative px-6 py-2.5 rounded-xl font-semibold text-white overflow-hidden bg-gradient-to-r from-accent-orange to-accent-orange-light hover:from-accent-orange-dark hover:to-accent-orange hover:shadow-[0_10px_40px_-10px_rgba(245,158,11,0.35)] hover:-translate-y-0.5 active:translate-y-0 focus:outline-none focus:ring-2 focus:ring-accent-orange/40 focus:ring-offset-2 transition-all duration-300 ease-out shadow-lg shadow-[0_4px_14px_-3px_rgba(245,158,11,0.25)]">
            <span class="absolute inset-0 bg-gradient-to-r from-white/0 via-white/20 to-white/0 -translate-x-full group-hover:translate-x-full transition-transform duration-500"></span>
            <span class="relative z-10">Register</span>
          </a>
        </div>

        <!-- Mobile: Hamburger -->
        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden p-2.5 rounded-xl text-gray-600 hover:bg-gray-100 transition" aria-label="Menu">
          <i class="bi text-2xl" :class="mobileMenuOpen ? 'bi-x-lg' : 'bi-list'"></i>
        </button>
      </div>
    </div>

    <!-- Mobile menu -->
    <div x-show="mobileMenuOpen" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" class="md:hidden border-t border-white/20 bg-white/80 backdrop-blur-xl">
      <div class="px-4 py-4 space-y-1">
        <a href="#home" @click="mobileMenuOpen = false" class="block px-4 py-3 rounded-xl text-gray-700 font-bold tracking-wide hover:bg-accent-blue/15 hover:text-accent-blue transition-all duration-200 active:scale-[0.98] border-l-2 border-transparent hover:border-accent-blue">Home</a>
        <a href="#free-samples" @click="mobileMenuOpen = false" class="block px-4 py-3 rounded-xl text-gray-700 font-bold tracking-wide hover:bg-accent-orange/15 hover:text-accent-orange transition-all duration-200 active:scale-[0.98] border-l-2 border-transparent hover:border-accent-orange">Free Samples</a>
        <a href="#packages" @click="mobileMenuOpen = false" class="block px-4 py-3 rounded-xl text-gray-700 font-bold tracking-wide hover:bg-accent-blue/15 hover:text-accent-blue transition-all duration-200 active:scale-[0.98] border-l-2 border-transparent hover:border-accent-blue">Packages</a>
        <a href="#about" @click="mobileMenuOpen = false" class="block px-4 py-3 rounded-xl text-gray-700 font-bold tracking-wide hover:bg-accent-orange/15 hover:text-accent-orange transition-all duration-200 active:scale-[0.98] border-l-2 border-transparent hover:border-accent-orange">About</a>
        <a href="#faqs" @click="mobileMenuOpen = false" class="block px-4 py-3 rounded-xl text-gray-700 font-bold tracking-wide hover:bg-accent-blue/15 hover:text-accent-blue transition-all duration-200 active:scale-[0.98] border-l-2 border-transparent hover:border-accent-blue">FAQs</a>
        <div class="pt-3 mt-3 border-t border-gray-200 flex gap-2">
          <a href="login.php" @click="mobileMenuOpen = false" class="flex-1 text-center py-3 rounded-xl font-semibold text-accent-blue border-2 border-accent-blue hover:bg-accent-blue hover:text-white transition-all duration-200 active:scale-[0.98]">Login</a>
          <a href="registration.php" @click="mobileMenuOpen = false" class="group relative flex-1 py-3 rounded-xl font-semibold text-white overflow-hidden bg-gradient-to-r from-accent-orange to-accent-orange-light hover:from-accent-orange-dark hover:to-accent-orange shadow-lg shadow-accent-orange/25 hover:shadow-[0_10px_40px_-10px_rgba(245,158,11,0.35)] hover:-translate-y-0.5 active:scale-[0.98] transition-all duration-300 ease-out">
            <span class="absolute inset-0 bg-gradient-to-r from-white/0 via-white/20 to-white/0 -translate-x-full group-hover:translate-x-full transition-transform duration-500"></span>
            <span class="relative z-10">Register</span>
          </a>
        </div>
      </div>
    </div>
  </nav>

  <!-- Hero Section: content builds up when section scrolls into view; class stays so content never vanishes -->
  <section id="home" class="scroll-reveal relative py-20 sm:py-24 md:py-32 overflow-hidden bg-gray-100" x-data="{ slideIndex: 0, init() { setInterval(() => { this.slideIndex = (this.slideIndex + 1) % 5; }, 5500); } }" x-intersect.once="$el.classList.add('revealed')">
    <!-- Image backdrop slideshow -->
    <?php
    $hero_slides = [
      'https://images.unsplash.com/photo-1522202176988-66273c2fd55f?w=1920&q=80',
      'https://images.unsplash.com/photo-1481627834876-b7833e8f5570?w=1920&q=80',
      'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=1920&q=80',
      'https://images.unsplash.com/photo-1456513080510-7bf3a84b82f8?w=1920&q=80',
      'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?w=1920&q=80',
    ];
    foreach ($hero_slides as $i => $url):
    ?>
    <div class="absolute inset-0 transition-opacity duration-[1200ms] ease-in-out" :class="slideIndex === <?php echo $i; ?> ? 'opacity-100 z-0' : 'opacity-0 z-0'">
      <img src="<?php echo h($url); ?>" alt="" class="absolute inset-0 w-full h-full object-cover object-center" loading="<?php echo $i === 0 ? 'eager' : 'lazy'; ?>">
    </div>
    <?php endforeach; ?>
    <!-- Subtle overlay for readability + brand tint -->
    <div class="absolute inset-0 bg-gradient-to-r from-white/95 via-white/80 to-white/60 pointer-events-none z-[1]"></div>
    <div class="absolute inset-0 bg-[radial-gradient(900px_450px_at_100%_0%,rgba(245,158,11,0.06)_0%,transparent_50%)] pointer-events-none z-[1]"></div>
    <div class="absolute inset-0 bg-[radial-gradient(700px_350px_at_0%_60%,rgba(31,88,195,0.05)_0%,transparent_50%)] pointer-events-none z-[1]"></div>
    <div class="absolute top-20 right-0 w-72 h-72 bg-accent-orange/5 rounded-full blur-3xl pointer-events-none z-[1]"></div>
    <div class="absolute bottom-20 left-0 w-96 h-96 bg-accent-blue/5 rounded-full blur-3xl pointer-events-none z-[1]"></div>
    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 z-10">
      <div class="grid lg:grid-cols-2 gap-14 lg:gap-20 items-center">
        <div class="reveal-content">
          <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-accent-blue/5 border border-accent-blue/20 text-accent-blue text-sm font-semibold tracking-wide mb-6">
            <span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-accent-orange opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-accent-orange"></span></span>
            CPA Review Platform
          </div>
          <h1 class="text-4xl sm:text-5xl lg:text-6xl xl:text-7xl font-extrabold tracking-tight text-gray-900 mb-6 leading-[1.1]">
            Welcome to <span class="relative inline-block"><span class="text-accent-blue relative z-10">LCRC</span></span> <span class="relative inline-block"><span class="text-accent-orange relative z-10">eReview</span></span>
          </h1>
          <p class="text-lg sm:text-xl text-gray-600 mb-10 max-w-xl leading-relaxed">
            Empowering future CPAs through modern review programs, flexible modules, and dedicated support.
          </p>
          <a href="#packages" class="group relative inline-flex items-center gap-3 px-8 py-4 rounded-2xl font-bold text-white overflow-hidden bg-gradient-to-r from-accent-orange to-accent-orange-light hover:from-accent-orange-dark hover:to-accent-orange shadow-lg shadow-accent-orange/25 hover:shadow-xl hover:shadow-accent-orange/30 hover:-translate-y-0.5 active:translate-y-0 transition-all duration-300 ease-out">
            <span class="absolute inset-0 bg-gradient-to-r from-white/0 via-white/20 to-white/0 -translate-x-full group-hover:translate-x-full transition-transform duration-500"></span>
            <span class="relative z-10">View Packages</span>
            <i class="bi bi-arrow-right text-lg relative z-10 group-hover:translate-x-1 transition-transform duration-300"></i>
          </a>
          <?php if (isset($_SESSION['message']) && !isset($_SESSION['open_modal'])): ?>
            <div class="mt-8 p-4 rounded-2xl bg-green-50/90 border border-green-200/80 flex items-center gap-3 text-green-800 backdrop-blur-sm">
              <i class="bi bi-check-circle-fill text-green-500 text-xl"></i>
              <span><?php echo h($_SESSION['message']); unset($_SESSION['message']); ?></span>
            </div>
          <?php endif; ?>
          <?php if (isset($_SESSION['error']) && !isset($_SESSION['open_modal'])): ?>
            <div class="mt-8 p-4 rounded-2xl bg-red-50/90 border border-red-200/80 flex items-center gap-3 text-red-800 backdrop-blur-sm">
              <i class="bi bi-exclamation-triangle-fill text-red-500 text-xl"></i>
              <span><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?></span>
            </div>
          <?php endif; ?>
        </div>
        <div class="grid grid-cols-2 gap-4 sm:gap-5 reveal-content reveal-delay-2">
          <?php
          $features = [
            ['icon' => 'bi-person-check', 'title' => 'Verified Enrollment', 'desc' => 'Register with payment proof and get admin-verified access.', 'bg' => 'bg-accent-orange/10', 'text' => 'text-accent-orange', 'border' => 'hover:border-accent-orange/40', 'hoverText' => 'group-hover/card:text-accent-orange'],
            ['icon' => 'bi-journal-check', 'title' => 'CPA-Focused Modules', 'desc' => 'Study by subject with lessons, handouts, and progress tracking.', 'bg' => 'bg-accent-blue/10', 'text' => 'text-accent-blue', 'border' => 'hover:border-accent-blue/40', 'hoverText' => 'group-hover/card:text-accent-blue'],
            ['icon' => 'bi-clipboard2-pulse', 'title' => 'Mock Exams & Results', 'desc' => 'Take timed quizzes and monitor your readiness in real time.', 'bg' => 'bg-accent-blue/10', 'text' => 'text-accent-blue', 'border' => 'hover:border-accent-blue/40', 'hoverText' => 'group-hover/card:text-accent-blue'],
            ['icon' => 'bi-graph-up-arrow', 'title' => 'Performance Insights', 'desc' => 'Track strengths, weak areas, and next topics to improve.', 'bg' => 'bg-accent-orange/10', 'text' => 'text-accent-orange', 'border' => 'hover:border-accent-orange/40', 'hoverText' => 'group-hover/card:text-accent-orange'],
          ];
          foreach ($features as $i => $f):
            $delay = 50 + ($i * 75);
          ?>
            <a href="#packages" class="feature-card group/card block p-5 sm:p-6 rounded-2xl bg-white border-2 border-gray-100 shadow-lg hover:shadow-xl hover:-translate-y-1.5 transition-all duration-300 <?php echo $f['border']; ?>" style="transition-delay: <?php echo $delay; ?>ms">
              <div class="w-12 h-12 rounded-xl <?php echo $f['bg']; ?> <?php echo $f['text']; ?> flex items-center justify-center text-xl mb-3 group-hover/card:scale-110 transition-transform duration-300">
                <i class="bi <?php echo $f['icon']; ?>"></i>
              </div>
              <div class="font-bold text-gray-900 <?php echo $f['hoverText']; ?> transition-colors duration-300"><?php echo h($f['title']); ?></div>
              <div class="text-sm text-gray-500 mt-1"><?php echo h($f['desc']); ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- Free Sample Videos Section: content builds up when section scrolls into view -->
  <section id="free-samples" class="scroll-reveal py-16 md:py-24 bg-white relative overflow-hidden" x-intersect.once="$el.classList.add('revealed')">
    <div class="absolute top-0 right-0 w-96 h-96 bg-accent-orange/5 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2 pointer-events-none"></div>
    <div class="absolute bottom-0 left-0 w-80 h-80 bg-accent-blue/5 rounded-full blur-3xl translate-y-1/2 -translate-x-1/2 pointer-events-none"></div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
      <div class="text-center mb-12 reveal-content">
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-accent-orange/10 border border-accent-orange/20 text-accent-orange text-xs font-semibold uppercase tracking-wider mb-4">
          <i class="bi bi-unlock-fill"></i> No sign-up required
        </span>
        <p class="text-accent-orange font-semibold text-sm uppercase tracking-wider mb-2">Free Preview</p>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 text-center mb-4">Free Sample Videos</h2>
        <div class="w-20 h-1 bg-gradient-to-r from-accent-orange to-accent-blue mx-auto rounded-full mb-4"></div>
        <p class="text-gray-600 text-center max-w-2xl mx-auto text-lg">Experience our quality content with these free sample lectures. No registration required!</p>
      </div>
      <div class="grid md:grid-cols-3 gap-6 lg:gap-8">
        <?php
        $sample_videos = [
          [
            'title' => 'Sample Lecture: Financial Accounting',
            'desc' => 'Get a preview of our comprehensive Financial Accounting course with expert instructors and real-world examples.',
            'instructor' => 'Expert CPA Instructor',
            'thumbnail' => 'https://images.unsplash.com/photo-1554224155-6726b3ff858f?w=600&q=80',
            'accent' => 'accent-orange',
          ],
          [
            'title' => 'Sample Lecture: Taxation',
            'desc' => 'Explore our Taxation review module with real-world examples, practice problems, and step-by-step solutions.',
            'instructor' => 'Certified Tax Specialist',
            'thumbnail' => 'https://images.unsplash.com/photo-1450101499163-c8848c66ca85?w=600&q=80',
            'accent' => 'accent-blue',
          ],
          [
            'title' => 'Sample Lecture: Auditing',
            'desc' => 'Learn auditing principles with our engaging and easy-to-understand teaching style. Perfect for exam prep.',
            'instructor' => 'CPA, MBA Instructor',
            'thumbnail' => 'https://images.unsplash.com/photo-1507679799987-c73779587ccf?w=600&q=80',
            'accent' => 'accent-orange',
          ],
        ];
        foreach ($sample_videos as $i => $v):
          $delay = $i === 0 ? '0' : ($i === 1 ? '1' : '3');
        ?>
        <div class="reveal-content reveal-delay-<?php echo $delay; ?> group/card rounded-2xl bg-white border-2 border-gray-100 overflow-hidden shadow-lg transition-all duration-400 <?php echo $v['accent'] === 'accent-orange' ? 'hover:border-accent-orange/50 hover:shadow-[0_20px_40px_-12px_rgba(245,158,11,0.25)]' : 'hover:border-accent-blue/50 hover:shadow-[0_20px_40px_-12px_rgba(31,88,195,0.25)]'; ?> hover:-translate-y-2 active:translate-y-0">
          <!-- Video thumbnail with play overlay -->
          <div class="relative aspect-video bg-gray-200 overflow-hidden">
            <img src="<?php echo h($v['thumbnail']); ?>" alt="<?php echo h($v['title']); ?>" class="absolute inset-0 w-full h-full object-cover group-hover/card:scale-110 transition-transform duration-500 ease-out" loading="lazy">
            <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent group-hover/card:from-black/40 transition-colors duration-400"></div>
            <a href="#packages" class="absolute inset-0 flex items-center justify-center">
              <span class="w-16 h-16 rounded-full bg-white/95 flex items-center justify-center shadow-xl group-hover/card:scale-125 group-hover/card:shadow-[0_0_0_12px_rgba(255,255,255,0.3)] transition-all duration-400 ease-out">
                <i class="bi bi-play-fill text-3xl text-accent-orange ml-1 group-hover/card:scale-110 transition-transform duration-300"></i>
              </span>
            </a>
            <span class="absolute bottom-3 left-3 px-2.5 py-1 rounded-lg bg-black/60 text-white text-xs font-medium backdrop-blur-sm group-hover/card:bg-black/75 transition-colors duration-300"><?php echo h($v['instructor']); ?></span>
          </div>
          <div class="p-5">
            <h3 class="font-bold text-gray-900 text-lg mb-2 transition-colors duration-300 <?php echo $v['accent'] === 'accent-orange' ? 'group-hover/card:text-accent-orange' : 'group-hover/card:text-accent-blue'; ?>"><?php echo h($v['title']); ?></h3>
            <p class="text-gray-600 text-sm leading-relaxed mb-4 group-hover/card:text-gray-700 transition-colors duration-300"><?php echo h($v['desc']); ?></p>
            <a href="#packages" class="group/btn inline-flex items-center gap-2 px-5 py-2.5 rounded-xl font-semibold text-white bg-gradient-to-r from-accent-orange to-accent-orange-light shadow-md transition-all duration-300 hover:from-accent-orange-dark hover:to-accent-orange hover:shadow-[0_12px_24px_-8px_rgba(245,158,11,0.5)] hover:-translate-y-1 hover:scale-[1.02] active:scale-[0.98] overflow-hidden relative">
              <span class="absolute inset-0 bg-gradient-to-r from-white/0 via-white/25 to-white/0 -translate-x-full group-hover/btn:translate-x-full transition-transform duration-500"></span>
              <i class="bi bi-play-circle text-lg relative z-10 group-hover/btn:scale-110 transition-transform duration-300"></i>
              <span class="relative z-10">Watch for Free</span>
            </a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="text-center mt-10 reveal-content reveal-delay-4">
        <p class="text-gray-600 mb-4">Want full access to all courses and materials?</p>
        <a href="#packages" class="group/cta relative inline-flex items-center gap-2 px-6 py-3 rounded-xl font-semibold text-accent-blue border-2 border-accent-blue overflow-hidden transition-all duration-300 hover:bg-accent-blue hover:text-white hover:shadow-[0_12px_24px_-8px_rgba(31,88,195,0.4)] hover:-translate-y-1 hover:scale-[1.02] active:scale-[0.98]">
          <span class="absolute inset-0 bg-gradient-to-r from-white/0 via-white/20 to-white/0 -translate-x-full group-hover/cta:translate-x-full transition-transform duration-500"></span>
          <span class="relative z-10">View All Packages</span>
          <i class="bi bi-arrow-right text-lg relative z-10 group-hover/cta:translate-x-1 transition-transform duration-300"></i>
        </a>
      </div>
    </div>
  </section>

  <!-- Packages Section: content builds up when section scrolls into view; class stays so content never vanishes -->
  <section id="packages" class="scroll-reveal py-16 md:py-24 bg-gray-50 relative overflow-hidden" x-intersect.once="$el.classList.add('revealed')">
    <div class="absolute top-0 left-0 w-80 h-80 bg-accent-orange/5 rounded-full blur-3xl -translate-x-1/2 -translate-y-1/2 pointer-events-none"></div>
    <div class="absolute bottom-0 right-0 w-96 h-96 bg-accent-blue/5 rounded-full blur-3xl translate-x-1/2 translate-y-1/2 pointer-events-none"></div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
      <div class="text-center mb-12 reveal-content">
        <p class="text-accent-orange font-semibold text-sm uppercase tracking-wider mb-2">Packages</p>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 text-center mb-4">CPA Online Review Package</h2>
        <div class="w-20 h-1 bg-gradient-to-r from-accent-orange to-accent-blue mx-auto rounded-full mb-4"></div>
        <p class="text-gray-600 text-center max-w-xl mx-auto">Your CPA dream starts here!</p>
      </div>
      <div class="grid md:grid-cols-3 gap-6 lg:gap-8">
        <?php
        $plans = [
          ['name' => '6 MONTHS', 'price' => '₱1,500', 'bar' => 'bg-accent-orange', 'accent' => 'orange'],
          ['name' => '9 MONTHS', 'price' => '₱2,000', 'bar' => 'bg-accent-blue', 'accent' => 'blue'],
          ['name' => '14 MONTHS', 'price' => '₱2,500', 'bar' => 'bg-accent-orange', 'accent' => 'orange'],
        ];
        $features_list = [
          '1,000+ Hours of Lecture videos',
          'Downloadable Handouts',
          '8,000+ Topical Quizzers',
          '12,000+ Test Banks',
          '5 Sets of Pre-boards',
          '2025 Pre-week <strong class="text-red-600">LIVE</strong> Lectures',
        ];
        foreach ($plans as $i => $plan):
          $shadow = $plan['accent'] === 'orange' ? 'hover:shadow-[0_20px_40px_-12px_rgba(245,158,11,0.25)]' : 'hover:shadow-[0_20px_40px_-12px_rgba(31,88,195,0.25)]';
          $border = $plan['accent'] === 'orange' ? 'hover:border-accent-orange/50' : 'hover:border-accent-blue/50';
        ?>
          <div class="group/card rounded-2xl bg-white border-2 border-gray-100 overflow-hidden shadow-lg transition-all duration-400 hover:-translate-y-2 <?php echo $shadow . ' ' . $border; ?> reveal-content reveal-delay-<?php echo $i === 0 ? '0' : ($i === 1 ? '1' : '3'); ?> active:translate-y-0">
            <div class="h-1.5 <?php echo $plan['bar']; ?> group-hover/card:opacity-90 transition-opacity duration-300"></div>
            <div class="p-6 border-b border-gray-100">
              <h3 class="text-lg font-extrabold text-gray-900 text-center transition-colors duration-300 <?php echo $plan['accent'] === 'orange' ? 'group-hover/card:text-accent-orange' : 'group-hover/card:text-accent-blue'; ?>"><?php echo h($plan['name']); ?></h3>
            </div>
            <div class="p-6">
              <ul class="space-y-3 text-gray-700">
                <?php foreach ($features_list as $item): ?>
                  <li class="flex gap-2 items-start text-sm">
                    <i class="bi bi-check2-circle text-accent-orange mt-0.5 shrink-0"></i>
                    <span><?php echo $item; ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
              <div class="text-center mt-6">
                <span class="text-2xl font-extrabold text-gray-900 group-hover/card:text-accent-blue transition-colors duration-300"><?php echo h($plan['price']); ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="text-center mt-10 reveal-content reveal-delay-4">
        <button @click="$dispatch('open-modal', 'registerModal')" class="group/btn relative inline-flex items-center gap-2 px-8 py-3.5 rounded-xl font-semibold text-white bg-gradient-to-r from-accent-orange to-accent-orange-light shadow-md overflow-hidden transition-all duration-300 hover:from-accent-orange-dark hover:to-accent-orange hover:shadow-[0_12px_24px_-8px_rgba(245,158,11,0.5)] hover:-translate-y-1 hover:scale-[1.02] active:scale-[0.98]">
          <span class="absolute inset-0 bg-gradient-to-r from-white/0 via-white/25 to-white/0 -translate-x-full group-hover/btn:translate-x-full transition-transform duration-500"></span>
          <i class="bi bi-box-arrow-in-right text-lg relative z-10 group-hover/btn:scale-110 transition-transform duration-300"></i>
          <span class="relative z-10">Enroll Now</span>
        </button>
      </div>
    </div>
  </section>

  <!-- About / Why Choose LCRC: content builds up when section scrolls into view; class stays so content never vanishes -->
  <section id="about" class="scroll-reveal py-16 md:py-24 bg-white relative overflow-hidden" x-intersect.once="$el.classList.add('revealed')">
    <div class="absolute top-0 right-0 w-96 h-96 bg-accent-blue/5 rounded-full blur-3xl translate-x-1/2 -translate-y-1/2 pointer-events-none"></div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
      <div class="text-center mb-12 reveal-content">
        <p class="text-accent-blue font-semibold text-sm uppercase tracking-wider mb-2">About</p>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 text-center mb-4">Why Choose LCRC eReview</h2>
        <div class="w-20 h-1 bg-gradient-to-r from-accent-blue to-accent-orange mx-auto rounded-full mb-4"></div>
        <p class="text-gray-600 text-center max-w-xl mx-auto">We guide aspiring CPAs with flexible programs, modern tools, and expert support.</p>
      </div>
      <div class="grid md:grid-cols-3 gap-6 lg:gap-8">
        <div class="group/card rounded-2xl bg-white border-2 border-gray-100 overflow-hidden shadow-lg transition-all duration-400 hover:-translate-y-2 hover:shadow-[0_20px_40px_-12px_rgba(245,158,11,0.25)] hover:border-accent-orange/50 reveal-content reveal-delay-0 active:translate-y-0">
          <div class="h-44 relative overflow-hidden">
            <div class="absolute inset-0 bg-cover bg-center transition-transform duration-500 group-hover/card:scale-105" style="background-image:url('https://images.unsplash.com/photo-1556761175-4b46a572b786?q=80&w=1200')"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-accent-blue/80 to-transparent group-hover/card:from-accent-blue/70 transition-colors duration-400"></div>
            <span class="absolute left-4 bottom-4 px-3 py-2 rounded-xl bg-white/95 backdrop-blur text-sm font-semibold text-gray-900 flex items-center gap-2 w-fit border border-accent-orange/30 group-hover/card:scale-105 group-hover/card:border-accent-orange/50 transition-all duration-300 shadow-sm">
              <i class="bi bi-check2-circle text-accent-orange"></i> Why Choose Us?
            </span>
          </div>
          <div class="p-5">
            <div class="font-bold text-gray-900 text-lg group-hover/card:text-accent-orange transition-colors duration-300">Comprehensive & updated materials</div>
            <p class="text-gray-600 mt-2 group-hover/card:text-gray-700 transition-colors duration-300">Get curated content and mentoring for every step.</p>
          </div>
        </div>
        <div class="group/card rounded-2xl bg-white border-2 border-gray-100 overflow-hidden shadow-lg transition-all duration-400 hover:-translate-y-2 hover:shadow-[0_20px_40px_-12px_rgba(31,88,195,0.25)] hover:border-accent-blue/50 reveal-content reveal-delay-1 active:translate-y-0">
          <div class="h-44 relative overflow-hidden">
            <div class="absolute inset-0 bg-cover bg-center transition-transform duration-500 group-hover/card:scale-105" style="background-image:url('https://images.unsplash.com/photo-1517245386807-bb43f82c33c4?q=80&w=1200')"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-accent-blue/80 to-transparent group-hover/card:from-accent-blue/70 transition-colors duration-400"></div>
            <span class="absolute left-4 bottom-4 px-3 py-2 rounded-xl bg-white/95 backdrop-blur text-sm font-semibold text-gray-900 flex items-center gap-2 w-fit border border-accent-blue/30 group-hover/card:scale-105 group-hover/card:border-accent-blue/50 transition-all duration-300 shadow-sm">
              <i class="bi bi-clock-history text-accent-blue"></i> Flexible Learning
            </span>
          </div>
          <div class="p-5">
            <div class="font-bold text-gray-900 text-lg group-hover/card:text-accent-blue transition-colors duration-300">Anytime, anywhere access</div>
            <p class="text-gray-600 mt-2 group-hover/card:text-gray-700 transition-colors duration-300">Learn at your pace via recorded sessions and modules.</p>
          </div>
        </div>
        <div class="group/card rounded-2xl bg-white border-2 border-gray-100 overflow-hidden shadow-lg transition-all duration-400 hover:-translate-y-2 hover:shadow-[0_20px_40px_-12px_rgba(245,158,11,0.25)] hover:border-accent-orange/50 reveal-content reveal-delay-3 active:translate-y-0">
          <div class="h-44 relative overflow-hidden">
            <div class="absolute inset-0 bg-cover bg-center transition-transform duration-500 group-hover/card:scale-105" style="background-image:url('https://images.unsplash.com/photo-1521737604893-d14cc237f11d?q=80&w=1200')"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-accent-orange/60 to-transparent group-hover/card:from-accent-orange/50 transition-colors duration-400"></div>
            <span class="absolute left-4 bottom-4 px-3 py-2 rounded-xl bg-white/95 backdrop-blur text-sm font-semibold text-gray-900 flex items-center gap-2 w-fit border border-accent-orange/30 group-hover/card:scale-105 group-hover/card:border-accent-orange/50 transition-all duration-300 shadow-sm">
              <i class="bi bi-shield-check text-accent-orange"></i> Support You Can Trust
            </span>
          </div>
          <div class="p-5">
            <div class="font-bold text-gray-900 text-lg group-hover/card:text-accent-orange transition-colors duration-300">Guidance from start to boards</div>
            <p class="text-gray-600 mt-2 group-hover/card:text-gray-700 transition-colors duration-300">Friendly team to assist you on your CPA journey.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- FAQ Section: content builds up when section scrolls into view; class stays so content never vanishes -->
  <section id="faqs" class="scroll-reveal py-16 md:py-24 bg-gray-50 relative overflow-hidden" x-data="{ open: 1 }" x-intersect.once="$el.classList.add('revealed')">
    <div class="absolute bottom-0 left-1/2 -translate-x-1/2 w-96 h-96 bg-accent-orange/5 rounded-full blur-3xl translate-y-1/2 pointer-events-none"></div>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 relative">
      <div class="text-center mb-10 reveal-content">
        <p class="text-accent-orange font-semibold text-sm uppercase tracking-wider mb-2">FAQs</p>
        <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 text-center mb-4">Frequently Asked Questions</h2>
        <div class="w-20 h-1 bg-gradient-to-r from-accent-orange to-accent-blue mx-auto rounded-full mb-4"></div>
        <p class="text-gray-600 text-center max-w-xl mx-auto">Quick answers to common questions about registration and access.</p>
      </div>
      <div class="space-y-3 reveal-content reveal-delay-2">
        <div class="group/faq rounded-2xl border-2 border-gray-100 bg-white overflow-hidden shadow-sm transition-all duration-300 hover:border-accent-blue/50 hover:shadow-[0_8px_24px_-8px_rgba(31,88,195,0.2)]">
          <button @click="open = open === 1 ? 0 : 1" class="w-full px-5 py-4 text-left font-semibold text-gray-900 flex justify-between items-center gap-4 hover:bg-accent-blue/5 transition-colors duration-300 group-hover/faq:bg-accent-blue/5">
            <span class="text-left">How do I register?</span>
            <i class="bi text-accent-blue text-lg shrink-0 transition-transform duration-300" :class="open === 1 ? 'bi-chevron-up rotate-0' : 'bi-chevron-down'"></i>
          </button>
          <div x-show="open === 1" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="px-5 pb-4 text-gray-600 border-t border-gray-100 bg-gray-50/50">
            Click Enroll Now or Register, fill out the form, and upload your payment proof. Wait for admin approval.
          </div>
        </div>
        <div class="group/faq rounded-2xl border-2 border-gray-100 bg-white overflow-hidden shadow-sm transition-all duration-300 hover:border-accent-orange/50 hover:shadow-[0_8px_24px_-8px_rgba(245,158,11,0.2)]">
          <button @click="open = open === 2 ? 0 : 2" class="w-full px-5 py-4 text-left font-semibold text-gray-900 flex justify-between items-center gap-4 hover:bg-accent-orange/5 transition-colors duration-300 group-hover/faq:bg-accent-orange/5">
            <span class="text-left">Where can I see my status?</span>
            <i class="bi text-accent-orange text-lg shrink-0 transition-transform duration-300" :class="open === 2 ? 'bi-chevron-up rotate-0' : 'bi-chevron-down'"></i>
          </button>
          <div x-show="open === 2" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="px-5 pb-4 text-gray-600 border-t border-gray-100 bg-gray-50/50">
            After login, your status is shown in the student dashboard. You will be redirected automatically when approved.
          </div>
        </div>
        <div class="group/faq rounded-2xl border-2 border-gray-100 bg-white overflow-hidden shadow-sm transition-all duration-300 hover:border-accent-blue/50 hover:shadow-[0_8px_24px_-8px_rgba(31,88,195,0.2)]">
          <button @click="open = open === 3 ? 0 : 3" class="w-full px-5 py-4 text-left font-semibold text-gray-900 flex justify-between items-center gap-4 hover:bg-accent-blue/5 transition-colors duration-300 group-hover/faq:bg-accent-blue/5">
            <span class="text-left">What files are accepted for proof?</span>
            <i class="bi text-accent-blue text-lg shrink-0 transition-transform duration-300" :class="open === 3 ? 'bi-chevron-up rotate-0' : 'bi-chevron-down'"></i>
          </button>
          <div x-show="open === 3" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="px-5 pb-4 text-gray-600 border-t border-gray-100 bg-gray-50/50">
            Images (JPG/PNG) and PDF are accepted, subject to the server upload limits.
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Call-to-Action Section: content builds up when section scrolls into view; class stays so content never vanishes -->
  <section class="scroll-reveal py-16 md:py-20 relative overflow-hidden bg-gradient-to-br from-accent-blue via-accent-blue to-[#1a3a6e]" x-intersect.once="$el.classList.add('revealed')">
    <div class="absolute inset-0 bg-[radial-gradient(800px_400px_at_80%_20%,rgba(245,158,11,0.2)_0%,transparent_50%)] pointer-events-none"></div>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative">
      <h2 class="text-3xl sm:text-4xl font-extrabold text-white mb-4 reveal-content">Ready to start your CPA journey?</h2>
      <p class="text-blue-100 mb-8 max-w-xl mx-auto reveal-content reveal-delay-1">Join LCRC eReview today and get access to comprehensive review materials and support.</p>
      <div class="flex flex-wrap justify-center gap-4 reveal-content reveal-delay-3">
        <button @click="$dispatch('open-modal', 'registerModal')" class="group/btn relative inline-flex items-center gap-2 px-8 py-3.5 rounded-xl font-semibold text-white bg-gradient-to-r from-accent-orange to-accent-orange-light border-2 border-accent-orange shadow-lg overflow-hidden transition-all duration-300 hover:from-accent-orange-dark hover:to-accent-orange hover:shadow-[0_12px_24px_-8px_rgba(245,158,11,0.5)] hover:-translate-y-1 hover:scale-[1.02] active:scale-[0.98]">
          <span class="absolute inset-0 bg-gradient-to-r from-white/0 via-white/25 to-white/0 -translate-x-full group-hover/btn:translate-x-full transition-transform duration-500"></span>
          <i class="bi bi-person-plus-fill text-lg relative z-10 group-hover/btn:scale-110 transition-transform duration-300"></i>
          <span class="relative z-10">Register Now</span>
        </button>
        <a href="#packages" class="group/cta relative inline-flex items-center gap-2 px-8 py-3.5 rounded-xl font-semibold text-white border-2 border-white/80 overflow-hidden transition-all duration-300 hover:bg-white hover:text-accent-blue hover:shadow-[0_12px_24px_-8px_rgba(255,255,255,0.3)] hover:-translate-y-1 hover:scale-[1.02] active:scale-[0.98]">
          <span class="absolute inset-0 bg-gradient-to-r from-white/0 via-white/25 to-white/0 -translate-x-full group-hover/cta:translate-x-full transition-transform duration-500"></span>
          <span class="relative z-10">View Packages</span>
          <i class="bi bi-arrow-right text-lg relative z-10 group-hover/cta:translate-x-1 transition-transform duration-300"></i>
        </a>
      </div>
    </div>
  </section>

  <!-- Footer: LCRC LMS – card style, subscribe + 3 columns + copyright (reference design, LCRC theme) -->
  <footer class="mt-auto px-4 sm:px-6 lg:px-8 py-8 pb-10">
    <div class="footer-card max-w-6xl mx-auto rounded-2xl overflow-hidden border border-gray-200/80 shadow-xl" style="box-shadow: 0 20px 50px -12px rgba(31, 88, 195, 0.15), 0 0 0 1px rgba(0,0,0,0.04);">
      <style>
        .footer-card .footer-subscribe { background-color: #1F58C3; color: #fff; text-align: center; padding: 2rem 1.5rem; }
        .footer-card .footer-subscribe-title { font-size: 1.25rem; font-weight: 700; color: #fff; margin-bottom: 1rem; }
        .footer-card .footer-subscribe-form { display: inline-flex; max-width: 100%; }
        .footer-card .footer-subscribe-input { border: none; padding: 12px 20px; font-size: 14px; border-radius: 9999px 0 0 9999px; min-width: 200px; background: #f1f5f9; color: #334155; }
        .footer-card .footer-subscribe-btn { position: relative; overflow: hidden; background: linear-gradient(to right, #F59E0B, #FCD34D); color: #fff; border: none; padding: 12px 24px; font-weight: 600; font-size: 13px; border-radius: 0 9999px 9999px 0; cursor: pointer; transition: box-shadow 0.3s, transform 0.3s; box-shadow: 0 4px 14px -3px rgba(245,158,11,0.25); }
        .footer-card .footer-subscribe-btn:hover { box-shadow: 0 10px 40px -10px rgba(245,158,11,0.35); transform: translateY(-2px); }
        .footer-card .footer-subscribe-btn::after { content: ''; position: absolute; inset: 0; background: linear-gradient(to right, transparent, rgba(255,255,255,0.25), transparent); transform: translateX(-100%); transition: transform 0.5s; }
        .footer-card .footer-subscribe-btn:hover::after { transform: translateX(100%); }
        .footer-card .footer-body { background: linear-gradient(135deg, #eef4fc 0%, #e2eaf5 50%, #dbe4f2 100%); padding: 2.5rem 1.5rem 2rem; }
        .footer-card .footer-body .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 2.5rem; }
        @media (max-width: 768px) { .footer-card .footer-body .grid { grid-template-columns: 1fr; gap: 2rem; } }
        .footer-card .footer-heading { font-weight: 700; font-size: 1rem; color: #1F58C3; margin-bottom: 1rem; }
        .footer-card .footer-link { color: #475569; text-decoration: none; font-size: 0.9375rem; transition: color 0.2s; display: inline-block; }
        .footer-card .footer-link:hover { color: #F59E0B; }
        .footer-card .footer-social-circle { width: 40px; height: 40px; border-radius: 50%; background-color: #1F58C3; color: #fff; display: inline-flex; align-items: center; justify-content: center; margin-right: 8px; transition: background 0.2s, transform 0.2s; }
        .footer-card .footer-social-circle:hover { background-color: #F59E0B; transform: scale(1.08); }
        .footer-card .footer-contact-text { color: #475569; font-size: 0.9375rem; line-height: 1.6; }
        .footer-card .footer-copy { background: linear-gradient(135deg, #e2eaf5 0%, #dbe4f2 100%); padding: 1rem 1.5rem; text-align: center; color: #64748b; font-size: 0.8125rem; }
      </style>

      <!-- Top: Subscribe -->
      <div class="footer-subscribe">
        <h2 class="footer-subscribe-title">Subscribe for latest updates</h2>
        <form class="footer-subscribe-form" action="#" method="post" onsubmit="return false;">
          <input type="email" placeholder="Enter your email address" class="footer-subscribe-input" aria-label="Email for newsletter">
          <button type="submit" class="footer-subscribe-btn">Subscribe</button>
        </form>
      </div>

      <!-- Middle: 3 columns -->
      <div class="footer-body">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 md:gap-10">
          <!-- Column 1: Brand + about + social -->
          <div>
            <a href="#home" class="inline-block mb-3">
              <span class="font-extrabold text-xl" style="color:#1F58C3">LCRC</span><span class="font-extrabold text-xl" style="color:#F59E0B"> eReview</span>
            </a>
            <p class="footer-contact-text mb-4">Empowering future CPAs through quality review programs, flexible modules, and dedicated support for your licensure journey.</p>
            <div>
              <a href="#" aria-label="Facebook" class="footer-social-circle"><i class="bi bi-facebook"></i></a>
              <a href="#" aria-label="Instagram" class="footer-social-circle"><i class="bi bi-instagram"></i></a>
              <a href="#" aria-label="YouTube" class="footer-social-circle"><i class="bi bi-youtube"></i></a>
            </div>
          </div>
          <!-- Column 2: Company -->
          <div>
            <h3 class="footer-heading">Company</h3>
            <ul class="space-y-2">
              <li><a href="#home" class="footer-link">Home</a></li>
              <li><a href="#free-samples" class="footer-link">Free Samples</a></li>
              <li><a href="#packages" class="footer-link">Packages</a></li>
              <li><a href="#about" class="footer-link">About</a></li>
              <li><a href="#faqs" class="footer-link">FAQs</a></li>
              <li><a href="login.php" class="footer-link">Login</a></li>
              <li><a href="#" @click.prevent="$dispatch('open-modal', 'registerModal')" class="footer-link" style="color:#F59E0B;">Register</a></li>
            </ul>
          </div>
          <!-- Column 3: Contact -->
          <div>
            <h3 class="footer-heading">Contact</h3>
            <div class="footer-contact-text space-y-2">
              <p>LCRC eReview · Philippines</p>
              <p><a href="mailto:contact@ereview.ph" class="footer-link">contact@ereview.ph</a></p>
              <p>Visit our platform for CPA review programs and support.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Bottom: Copyright -->
      <div class="footer-copy">
        <p>© Copyright <?php echo date('Y'); ?> LCRC eReview. All rights reserved. · Built for aspiring CPAs</p>
      </div>
    </div>
  </footer>
</div>

<!-- Floating AI Chatbot (bottom right) -->
<div id="chatbot-container" class="fixed bottom-6 right-6 z-[9999] chatbot-wrapper" x-data="chatbot()" x-init="init()" @keydown.escape.window="open = false" style="position: fixed !important; bottom: 1.5rem !important; right: 1.5rem !important; z-index: 9999 !important; pointer-events: auto;">
  <!-- Chat panel -->
  <div x-show="open" 
       x-cloak
       x-ref="chatbotPanel"
       @click.outside="open = false"
        x-transition:enter="transition duration-500" 
        x-transition:enter-start="opacity-0 translate-y-6 scale-95 rotate-1" 
        x-transition:enter-end="opacity-100 translate-y-0 scale-100 rotate-0" 
        x-transition:leave="transition duration-250 ease-in" 
        x-transition:leave-start="opacity-100 translate-y-0 scale-100 rotate-0" 
        x-transition:leave-end="opacity-0 translate-y-4 scale-98 rotate-1" 
       class="chatbot-panel absolute right-0 rounded-3xl overflow-hidden border border-gray-200/80 bg-white shadow-2xl backdrop-blur-sm" 
       :class="open ? 'anim-chat-open' : 'anim-chat-close'"
       style="bottom: calc(100% + 1rem); height: 600px; max-height: min(600px, calc(100vh - 8rem)); min-height: 420px;">
    <!-- Enhanced Header -->
    <div class="chatbot-header relative flex items-center gap-4 px-6 py-5 border-b border-white/20 text-white overflow-hidden" style="background: linear-gradient(135deg, #1F58C3 0%, #1a3a6e 100%);">
      <div class="absolute inset-0 bg-gradient-to-r from-white/0 via-white/10 to-white/0 opacity-50"></div>
      <div class="relative w-14 h-14 rounded-2xl flex items-center justify-center shadow-lg border border-white/30 group/icon hover:scale-105 transition-all duration-300 cursor-pointer" style="background: rgba(255,255,255,0.2);">
        <i class="bi bi-chat-dots-fill text-2xl text-white drop-shadow-sm group-hover/icon:scale-110 transition-transform duration-300"></i>
      </div>
      <div class="relative flex-1 min-w-0">
        <h3 class="font-bold text-lg tracking-tight">LCRC Support</h3>
        <p class="text-sm mt-1 flex items-center gap-2" style="color: rgba(255,255,255,0.9);">
          <span class="relative flex h-2.5 w-2.5 shrink-0">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75" style="background: #4ade80;"></span>
            <span class="relative inline-flex rounded-full h-2.5 w-2.5" style="background: #4ade80;"></span>
          </span>
          Online • Ask about packages, registration & more
        </p>
      </div>
    </div>
    
    <!-- Enhanced Messages Area -->
    <div class="chatbot-messages flex-1 overflow-y-auto p-6 space-y-4 bg-gradient-to-b from-gray-50/80 via-white to-gray-50/50" style="min-height: 0; max-height: 100%; overflow-y: auto;" x-ref="messagesEl">
      <template x-for="(msg, i) in messages" :key="i">
        <div class="message-wrapper" 
             :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'"
             x-data="{ show: false }"
             x-init="$nextTick(() => { setTimeout(() => show = true, i * 50) })"
             x-show="show"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0">
          <div :class="msg.role === 'user' 
            ? 'group/user max-w-[85%] px-5 py-3 rounded-2xl rounded-br-md text-white text-[15px] shadow-lg hover:shadow-xl hover:scale-[1.02] transition-all duration-200' 
            : 'group/bot max-w-[85%] px-5 py-3 rounded-2xl rounded-bl-md bg-white border-2 border-gray-100 text-gray-800 text-[15px] shadow-md hover:shadow-lg hover:border-accent-blue/30 hover:scale-[1.01] transition-all duration-200'"
            :style="msg.role === 'user' && 'background: linear-gradient(135deg, #1F58C3 0%, #1E40AF 100%);'">
            <p class="leading-relaxed" x-text="msg.text"></p>
          </div>
        </div>
      </template>
      <!-- Typing Indicator -->
      <div x-show="typing" 
           x-transition:enter="transition ease-out duration-200"
           x-transition:enter-start="opacity-0 scale-95"
           x-transition:enter-end="opacity-100 scale-100"
           class="flex justify-start">
        <div class="group/bot max-w-[85%] px-5 py-3.5 rounded-2xl rounded-bl-md bg-white border-2 border-gray-100 text-gray-800 shadow-md">
          <div class="flex items-center gap-2">
            <span class="typing-dot w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0s;"></span>
            <span class="typing-dot w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.2s;"></span>
            <span class="typing-dot w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.4s;"></span>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Enhanced Input Area -->
    <div class="chatbot-input p-5 border-t border-gray-100/80 bg-gradient-to-r from-white via-gray-50/50 to-white">
      <form @submit.prevent="send()" class="flex gap-3">
        <div class="flex-1 relative group/input min-w-0">
          <input type="text" 
                 x-model="input" 
                 placeholder="Type your message..." 
                 class="w-full px-5 py-3.5 rounded-2xl border-2 border-gray-200 bg-white text-[15px] focus:outline-none focus:ring-2 focus:ring-accent-orange/40 focus:border-accent-orange transition-all duration-200 placeholder:text-gray-400 group-hover/input:border-gray-300 shadow-sm" 
                 maxlength="500">
          <div class="absolute inset-0 rounded-2xl bg-gradient-to-r from-accent-orange/0 via-accent-orange/5 to-accent-orange/0 opacity-0 group-hover/input:opacity-100 transition-opacity duration-200 pointer-events-none"></div>
        </div>
        <button type="submit" 
                class="group/send shrink-0 rounded-2xl text-white flex items-center justify-center shadow-lg hover:shadow-xl hover:scale-105 active:scale-95 transition-all duration-300 relative overflow-hidden"
                style="background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); width: 4rem; height: 4rem; min-width: 4rem; min-height: 4rem;">
          <span class="absolute inset-0 bg-gradient-to-r from-white/0 via-white/30 to-white/0 translate-x-[-100%] group-hover/send:translate-x-[100%] transition-transform duration-700"></span>
          <i class="bi bi-send-fill text-xl relative z-10 group-hover/send:scale-110 transition-transform duration-300" style="font-size: 1.25rem;"></i>
        </button>
      </form>
    </div>
  </div>
  
  <!-- Enhanced Floating Button - LCRC color theme (orange = chat, blue = close) -->
  <button @click="console.log('[Chatbot] Button clicked, open before:', open); open = !open; console.log('[Chatbot] open after:', open);" 
          class="chatbot-button group relative rounded-2xl text-white flex items-center justify-center transition-all duration-300 focus:outline-none focus:ring-4 focus:ring-offset-2 overflow-hidden"
          :class="[
            !open ? 'animate-pulse-slow chatbot-btn-open is-closed' : 'chatbot-btn-close is-open'
          ]"
          aria-label="Open chat" 
          style="width: 4.5rem !important; height: 4.5rem !important; display: flex !important; align-items: center !important; justify-content: center !important; cursor: pointer !important;">
    <!-- Shine effect -->
    <span class="absolute inset-0 bg-gradient-to-r from-white/0 via-white/25 to-white/0 translate-x-[-100%] group-hover:translate-x-[100%] transition-transform duration-700"></span>
    <!-- Ripple on hover -->
    <span class="absolute inset-0 rounded-2xl bg-white/15 scale-0 group-hover:scale-150 opacity-0 group-hover:opacity-100 transition-all duration-500"></span>
    <!-- Icon -->
    <i class="bi icon relative z-10 transition-all duration-300" 
       style="font-size: 1.5rem;"
       :class="open ? 'bi-x-lg group-hover:rotate-90' : 'bi-chat-dots-fill group-hover:scale-110'"></i>
    <span x-show="!open" class="notify-dot absolute top-1 right-1 w-2.5 h-2.5 rounded-full border-2 border-white shadow" style="background: #1F58C3;"></span>
  </button>
</div>

<script>
// Ensure chatbot function is available globally before Alpine initializes
window.chatbot = function chatbot() {
  return {
    open: false,
    input: '',
    messages: [],
    typing: false,
    init() {
      // DEBUG: Log initial state
      console.log('[Chatbot] Initializing with open =', this.open);
      
      // Force open to false on init
      this.open = false;
      console.log('[Chatbot] Set open to false, current value:', this.open);
      
      this.messages = [{
        role: 'bot',
        text: 'Hi! 👋 I\'m here to help with LCRC eReview. Ask about packages, registration, or FAQs.'
      }];
      
      // DEBUG: Check panel visibility
      this.$nextTick(() => {
        const panel = document.querySelector('.chatbot-panel');
        if (panel) {
          console.log('[Chatbot] Panel element found:', panel);
          console.log('[Chatbot] Panel computed style display:', window.getComputedStyle(panel).display);
          console.log('[Chatbot] Panel inline style:', panel.style.display);
          console.log('[Chatbot] Panel has x-cloak:', panel.hasAttribute('x-cloak'));
        }
      });
      
      // Auto-scroll to bottom on init (only if open)
      if (this.open) {
        this.$nextTick(() => {
          setTimeout(() => {
            const el = this.$refs.messagesEl;
            if (el) {
              el.scrollTop = el.scrollHeight;
            }
          }, 100);
        });
      }
      
      // Watch for open state changes with debugging
      this.$watch('open', (isOpen) => {
        console.log('[Chatbot] open state changed to:', isOpen);
        const panel = document.querySelector('.chatbot-panel');
        if (panel) {
          console.log('[Chatbot] Panel display after change:', window.getComputedStyle(panel).display);
        }
        
        if (isOpen) {
          this.$nextTick(() => {
            setTimeout(() => {
              const el = this.$refs.messagesEl;
              if (el) {
                el.scrollTop = el.scrollHeight;
              }
            }, 300);
          });
        }
      });
    },
    send() {
      const text = (this.input || '').trim();
      if (!text || this.typing) return;
      
      // Add user message
      this.messages.push({ role: 'user', text });
      this.input = '';
      
      // Scroll to show user message
      this.$nextTick(() => {
        const el = this.$refs.messagesEl;
        if (el) {
          el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
        }
      });
      
      // Show typing indicator
      this.typing = true;
      const reply = this.getReply(text);
      
      // Simulate typing delay based on message length
      const typingDelay = Math.min(800, Math.max(400, reply.length * 20));
      
      setTimeout(() => {
        this.typing = false;
        this.messages.push({ role: 'bot', text: reply });
        // Ensure scroll to bottom after message is added
        this.$nextTick(() => {
          setTimeout(() => {
            const el = this.$refs.messagesEl;
            if (el) {
              el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
            }
          }, 100);
        });
      }, typingDelay);
    },
    getReply(userText) {
      const t = userText.toLowerCase();
      if (/hello|hi|hey|good (morning|afternoon|evening)/.test(t)) {
        return 'Hello! 👋 How can I help you today? You can ask about our CPA review packages, registration, or visit the FAQs section.';
      }
      if (/package|price|cost|fee|enroll|plan/.test(t)) {
        return '📦 We offer 6-month (₱1,500), 9-month (₱2,000), and 14-month (₱2,500) packages. Click "View Packages" on the page or scroll to the Packages section for details.';
      }
      if (/register|sign up|account|how to join/.test(t)) {
        return '✍️ Click the "Register" button at the top, fill out the form, and upload your payment proof. After admin approval, you\'ll get access to the platform.';
      }
      if (/contact|email|support|help/.test(t)) {
        return '📧 You can reach us at contact@ereview.ph. For quick answers, check the FAQs section on this page.';
      }
      if (/faq|question|where can i/.test(t)) {
        return '❓ Scroll down to the "Frequently Asked Questions" section for common answers. You can also email contact@ereview.ph for specific queries.';
      }
      return '💬 For detailed support, email us at contact@ereview.ph or check the FAQs section on this page. Is there anything else I can help with?';
    }
  };
}
</script>

<!-- Modals (Alpine) -->
<div x-data="{
  activeModal: null,
  loginFormKey: 0,
  registerFormKey: 0,
  login: {
    values: { email: '', password: '' },
    touched: { email: false, password: false },
    submit: false
  },
  register: {
    values: { full_name: '', email: '', school: '', school_other: '', review_type: '', password: '', hasProof: false },
    touched: { full_name: false, email: false, school: false, school_other: false, review_type: false, password: false, payment_proof: false },
    submit: false
  },
  open(name) { this.activeModal = name; document.body.classList.add('overflow-hidden'); this.resetFormUi(name); if (name === 'loginModal') this.loginFormKey = Date.now(); if (name === 'registerModal') this.registerFormKey = Date.now(); },
  close() { this.activeModal = null; document.body.classList.remove('overflow-hidden'); this.resetFormUi(); },
  resetFormUi(scope = null) {
    if (!scope || scope === 'loginModal') {
      this.login.submit = false;
      this.login.touched = { email: false, password: false };
    }
    if (!scope || scope === 'registerModal') {
      this.register.submit = false;
      this.register.touched = { full_name: false, email: false, school: false, school_other: false, review_type: false, password: false, payment_proof: false };
    }
  },
  isEmail(v) { var s=(v||'').trim(); if(!s) return false; var i=s.indexOf('@'); if(i<=0||i>=s.length-1) return false; var d=s.slice(i+1); return d.indexOf('.')>0 && d.lastIndexOf('.')<d.length-1; },
  loginError(field) {
    const email = (this.login.values.email || '').trim();
    const password = (this.login.values.password || '');
    if (field === 'email') {
      if (!email) return 'Email address is required.';
      if (!this.isEmail(email)) return 'Enter a valid email address.';
    }
    if (field === 'password') {
      if (!password) return 'Password is required.';
    }
    return '';
  },
  showLoginError(field) {
    const msg = this.loginError(field);
    return !!msg && (this.login.submit || this.login.touched[field]);
  },
  registerError(field) {
    const fullName = (this.register.values.full_name || '').trim();
    const email = (this.register.values.email || '').trim();
    const school = (this.register.values.school || '');
    const schoolOther = (this.register.values.school_other || '').trim();
    const reviewType = (this.register.values.review_type || '');
    const password = (this.register.values.password || '');
    const hasProof = !!this.register.values.hasProof;

    if (field === 'full_name') {
      if (!fullName) return 'Full name is required.';
    }
    if (field === 'email') {
      if (!email) return 'Email address is required.';
      if (!this.isEmail(email)) return 'Enter a valid email address.';
    }
    if (field === 'school') {
      if (!school) return 'Please select your school.';
    }
    if (field === 'school_other') {
      if (school === 'Other' && !schoolOther) return 'Please enter your school name.';
    }
    if (field === 'review_type') {
      if (!reviewType) return 'Please select a review type.';
    }
    if (field === 'password') {
      if (!password) return 'Password is required.';
      if (password.length < 6) return 'Password must be at least 6 characters.';
    }
    if (field === 'payment_proof') {
      if (!hasProof) return 'Payment proof is required.';
    }
    return '';
  },
  showRegisterError(field) {
    const msg = this.registerError(field);
    return !!msg && (this.register.submit || this.register.touched[field]);
  },
  loginSubmit(e) {
    this.login.submit = true;
    const order = ['email', 'password'];
    const firstInvalid = order.find((f) => !!this.loginError(f));
    if (firstInvalid) {
      e.preventDefault();
      this.$nextTick(() => {
        const el = this.$refs[`login_${firstInvalid}`];
        if (el && typeof el.focus === 'function') el.focus();
      });
    }
  },
  registerSubmit(e) {
    this.register.submit = true;
    const order = ['full_name', 'email', 'school', 'review_type', 'school_other', 'password', 'payment_proof'];
    const effectiveOrder = order.filter((f) => f !== 'school_other' || this.register.values.school === 'Other');
    const firstInvalid = effectiveOrder.find((f) => !!this.registerError(f));
    if (firstInvalid) {
      e.preventDefault();
      this.$nextTick(() => {
        const el = this.$refs[`reg_${firstInvalid}`];
        if (el && typeof el.focus === 'function') el.focus();
      });
    }
  }
}"
  @open-modal.window="open($event.detail)"
  x-cloak>
  <!-- Backdrop -->
  <div x-show="activeModal"
       x-transition:enter="transition ease-out duration-200"
       x-transition:enter-start="opacity-0"
       x-transition:enter-end="opacity-100"
       x-transition:leave="transition ease-in duration-150"
       x-transition:leave-start="opacity-100"
       x-transition:leave-end="opacity-0"
       class="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm"
       @click="close()"></div>

  <!-- Login Modal (modernized) -->
  <div x-show="activeModal === 'loginModal'"
       x-transition:enter="transition ease-out duration-300"
       x-transition:enter-start="opacity-0"
       x-transition:enter-end="opacity-100"
       x-transition:leave="transition ease-in duration-200"
       class="fixed inset-0 z-50 flex items-center justify-center p-4 pointer-events-none">
    <div @click.stop
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         class="bg-white/90 backdrop-blur-2xl rounded-3xl shadow-modal-xl w-full max-w-md pointer-events-auto overflow-hidden border border-white/60 ring-1 ring-black/5"
         role="dialog" aria-modal="true" aria-labelledby="login-title">
      <div class="relative px-8 pt-10 pb-6 text-center">
        <button @click="close()" class="absolute right-5 top-5 p-2.5 rounded-xl text-gray-400 hover:text-gray-800 hover:bg-gray-200/80 active:scale-95 focus:outline-none focus:ring-4 focus:ring-accent-blue/15 transition-all duration-200" aria-label="Close">
          <i class="bi bi-x-lg text-lg"></i>
        </button>

        <img src="image%20assets/lms-logo.png" alt="LCRC eReview" class="mx-auto h-20 w-auto max-h-24 select-none sm:h-24" loading="eager" decoding="async">

        <h2 id="login-title" class="mt-6 text-3xl font-extrabold tracking-[0.22em] text-brand-navy-dark uppercase">Login</h2>
        <p class="mt-3 text-lg font-bold text-gray-900">Welcome back</p>
        <p class="mt-1 text-sm text-gray-600">Sign in to continue to LCRC eReview</p>
      </div>

      <div class="h-px bg-gray-200/80 mx-8"></div>

      <div class="px-8 pt-6 pb-8" :key="loginFormKey">
        <?php
        $showLoginMessage = isset($_SESSION['message']) && isset($_SESSION['open_modal']) && $_SESSION['open_modal'] === 'loginModal';
        if ($showLoginMessage):
          $successMsg = $_SESSION['message'];
          unset($_SESSION['message']);
        ?>
          <div class="mb-5 p-4 rounded-xl bg-green-50 border border-green-200/80 flex items-center gap-3 text-green-800 animate-slide-up-fade">
            <i class="bi bi-check-circle-fill text-green-500 text-lg"></i>
            <span class="font-semibold"><?php echo h($successMsg); ?></span>
          </div>
        <?php endif; ?>
        <?php
        $showLoginError = isset($_SESSION['error']) && isset($_SESSION['open_modal']) && $_SESSION['open_modal'] === 'loginModal';
        if ($showLoginError):
          $errorMsg = $_SESSION['error'];
          unset($_SESSION['error']);
        ?>
          <div class="mb-5 p-4 rounded-xl bg-red-50 border border-red-200/80 flex items-center gap-3 text-red-800 animate-slide-up-fade">
            <i class="bi bi-exclamation-triangle-fill text-red-500 text-lg"></i>
            <span class="font-semibold"><?php echo h($errorMsg); ?></span>
          </div>
        <?php endif; ?>
        <form action="login_process.php" method="POST" class="space-y-6" novalidate @submit="loginSubmit($event)">
          <div class="animate-slide-up-fade-1">
            <label for="login-email" class="block text-sm font-semibold text-gray-700 mb-2">Email Address</label>
            <input id="login-email" x-ref="login_email" x-model.trim="login.values.email" @blur="login.touched.email = true"
                   type="email" name="email" required placeholder="you@example.com" autocomplete="email"
                   :aria-invalid="showLoginError('email') ? 'true' : 'false'"
                   aria-describedby="login-email-error"
                   :class="showLoginError('email') ? 'border-red-400 focus:border-red-500 focus:ring-red-500/15' : ''"
                   class="w-full rounded-2xl border border-gray-300 bg-white/80 px-4 py-4 text-gray-900 placeholder-gray-400 shadow-sm hover:border-gray-400 hover:bg-white hover:shadow-md focus:border-accent-blue focus:ring-4 focus:ring-accent-blue/15 outline-none transition-all duration-200">
            <p id="login-email-error" x-show="showLoginError('email')" x-transition.opacity class="mt-2 text-xs font-semibold text-red-600" x-text="loginError('email')"></p>
          </div>
          <div class="animate-slide-up-fade-2">
            <label for="login-password" class="block text-sm font-semibold text-gray-700 mb-2">Password</label>
            <div class="relative">
              <input id="login-password" x-ref="login_password" x-model="login.values.password" @blur="login.touched.password = true"
                     :type="showLoginPass ? 'text' : 'password'" name="password" required placeholder="••••••••"
                     autocomplete="current-password"
                     :aria-invalid="showLoginError('password') ? 'true' : 'false'"
                     aria-describedby="login-password-error"
                     :class="showLoginError('password') ? 'border-red-400 focus:border-red-500 focus:ring-red-500/15' : ''"
                     class="w-full rounded-2xl border border-gray-300 bg-white/80 px-4 py-4 pr-12 text-gray-900 placeholder-gray-400 shadow-sm hover:border-gray-400 hover:bg-white hover:shadow-md focus:border-accent-blue focus:ring-4 focus:ring-accent-blue/15 outline-none transition-all duration-200">
              <button type="button" @click="showLoginPass = !showLoginPass" class="absolute right-4 top-1/2 -translate-y-1/2 p-1.5 rounded-lg text-gray-400 hover:text-accent-blue hover:bg-accent-blue/10 active:scale-90 focus:outline-none focus:ring-4 focus:ring-accent-blue/15 transition-all duration-200" aria-label="Toggle password visibility">
                <i class="bi text-xl" :class="showLoginPass ? 'bi-eye-slash' : 'bi-eye'"></i>
              </button>
            </div>
            <p id="login-password-error" x-show="showLoginError('password')" x-transition.opacity class="mt-2 text-xs font-semibold text-red-600" x-text="loginError('password')"></p>
            <p class="mt-2 text-xs text-gray-500">Admin login supported</p>
          </div>
          <div class="animate-slide-up-fade-3">
          <button type="submit" name="login" class="w-full py-4 px-5 rounded-2xl font-semibold text-white bg-gradient-to-r from-accent-orange to-accent-orange-light hover:from-accent-orange-dark hover:to-accent-orange focus:outline-none focus:ring-4 focus:ring-accent-orange/25 transition-all duration-200 shadow-[0_14px_28px_rgba(245,158,11,0.25)] hover:shadow-[0_18px_36px_rgba(245,158,11,0.35)] hover:scale-[1.01] active:scale-[0.99] active:translate-y-[2px] active:shadow-[0_8px_20px_rgba(245,158,11,0.3)]">
            Login
          </button>
          </div>
        </form>
        <p class="text-center mt-6 text-sm text-gray-600 animate-slide-up-fade-4">No account? <a href="#" @click.prevent="activeModal = null; $dispatch('open-modal', 'registerModal')" class="text-accent-blue font-semibold hover:text-accent-blue-dark hover:underline underline-offset-2 transition-all duration-200">Create one</a></p>
      </div>
    </div>
  </div>

  <!-- Register Modal (modernized) -->
  <div x-show="activeModal === 'registerModal'"
       x-transition:enter="transition ease-out duration-300"
       x-transition:enter-start="opacity-0"
       x-transition:enter-end="opacity-100"
       x-transition:leave="transition ease-in duration-200"
       class="fixed inset-0 z-50 flex items-center justify-center p-4 pointer-events-none">
    <div @click.stop
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         class="bg-white/90 backdrop-blur-2xl rounded-3xl shadow-modal-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto pointer-events-auto border border-white/60 ring-1 ring-black/5"
         role="dialog" aria-modal="true" aria-labelledby="register-title">
      <div class="relative px-8 pt-10 pb-6 text-center sticky top-0 bg-white/90 backdrop-blur-xl z-10 border-b border-gray-200/80">
        <button @click="close()" class="absolute right-5 top-5 p-2.5 rounded-xl text-gray-400 hover:text-gray-800 hover:bg-gray-200/80 active:scale-95 focus:outline-none focus:ring-4 focus:ring-accent-blue/15 transition-all duration-200" aria-label="Close">
          <i class="bi bi-x-lg text-lg"></i>
        </button>

        <img src="image%20assets/lms-logo.png" alt="LCRC eReview" class="mx-auto h-20 w-auto max-h-24 select-none sm:h-24" loading="eager" decoding="async">

        <h2 id="register-title" class="mt-6 text-2xl font-bold text-gray-900 tracking-tight">Create your account</h2>
        <p class="mt-2 text-sm text-gray-600">Register to start your eReview journey.</p>
      </div>

      <div class="px-8 pt-6 pb-10" :key="registerFormKey">
        <?php
        $showRegisterError = isset($_SESSION['error']) && isset($_SESSION['open_modal']) && $_SESSION['open_modal'] === 'registerModal';
        if ($showRegisterError):
          $errorMsg = $_SESSION['error'];
          unset($_SESSION['error']);
        ?>
          <div class="mb-5 p-4 rounded-xl bg-red-50 border border-red-200/80 flex items-center gap-3 text-red-800 animate-slide-up-fade">
            <i class="bi bi-exclamation-triangle-fill text-red-500 text-lg"></i>
            <span class="font-semibold"><?php echo h($errorMsg); ?></span>
          </div>
        <?php endif; ?>
        <form action="register_process.php" method="POST" enctype="multipart/form-data" class="space-y-6" novalidate @submit="registerSubmit($event)">
          <div class="grid sm:grid-cols-2 gap-6">
            <div class="animate-slide-up-fade-1">
              <label for="reg-fullname" class="block text-sm font-semibold text-gray-700 mb-2">Full Name</label>
              <div class="relative">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"><i class="bi bi-person text-lg"></i></span>
                <input id="reg-fullname" x-ref="reg_full_name" x-model.trim="register.values.full_name" @blur="register.touched.full_name = true"
                       type="text" name="full_name" required placeholder="Your full name" autocomplete="name"
                       :aria-invalid="showRegisterError('full_name') ? 'true' : 'false'"
                       aria-describedby="reg-fullname-error"
                       :class="showRegisterError('full_name') ? 'border-red-400 focus:border-red-500 focus:ring-red-500/15' : ''"
                       class="w-full rounded-2xl border border-gray-300 bg-white/80 pl-12 pr-4 py-4 text-gray-900 placeholder-gray-400 shadow-sm hover:border-gray-400 hover:bg-white hover:shadow-md focus:border-accent-blue focus:ring-4 focus:ring-accent-blue/15 outline-none transition-all duration-200">
              </div>
              <p id="reg-fullname-error" x-show="showRegisterError('full_name')" x-transition.opacity class="mt-2 text-xs font-semibold text-red-600" x-text="registerError('full_name')"></p>
            </div>

            <div class="animate-slide-up-fade-2">
              <label for="reg-email" class="block text-sm font-semibold text-gray-700 mb-2">Email Address</label>
              <div class="relative">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"><i class="bi bi-envelope text-lg"></i></span>
                <input id="reg-email" x-ref="reg_email" x-model.trim="register.values.email" @blur="register.touched.email = true"
                       type="email" name="email" required placeholder="you@example.com" autocomplete="email"
                       :aria-invalid="showRegisterError('email') ? 'true' : 'false'"
                       aria-describedby="reg-email-error"
                       :class="showRegisterError('email') ? 'border-red-400 focus:border-red-500 focus:ring-red-500/15' : ''"
                       class="w-full rounded-2xl border border-gray-300 bg-white/80 pl-12 pr-4 py-4 text-gray-900 placeholder-gray-400 shadow-sm hover:border-gray-400 hover:bg-white hover:shadow-md focus:border-accent-blue focus:ring-4 focus:ring-accent-blue/15 outline-none transition-all duration-200">
              </div>
              <p id="reg-email-error" x-show="showRegisterError('email')" x-transition.opacity class="mt-2 text-xs font-semibold text-red-600" x-text="registerError('email')"></p>
            </div>

            <div class="animate-slide-up-fade-3">
              <label for="reg-school" class="block text-sm font-semibold text-gray-700 mb-2">School</label>
              <select id="reg-school" x-ref="reg_school" x-model="register.values.school" @blur="register.touched.school = true"
                      name="school" required
                      :aria-invalid="showRegisterError('school') ? 'true' : 'false'"
                      aria-describedby="reg-school-error"
                      :class="showRegisterError('school') ? 'border-red-400 focus:border-red-500 focus:ring-red-500/15' : ''"
                      class="w-full rounded-2xl border border-gray-300 bg-white/80 px-4 py-4 text-gray-900 shadow-sm hover:border-gray-400 hover:bg-white hover:shadow-md focus:border-accent-blue focus:ring-4 focus:ring-accent-blue/15 outline-none transition-all duration-200 appearance-none bg-[length:1.25rem] bg-[right_0.75rem_center] bg-no-repeat cursor-pointer" style="background-image:url('data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 fill=%27none%27 viewBox=%270 0 24 24%27 stroke=%27%236b7280%27%3E%3Cpath stroke-linecap=%27round%27 stroke-linejoin=%27round%27 stroke-width=%272%27 d=%27M19 9l-7 7-7-7%27/%3E%3C/svg%3E');">
                <option value="" disabled selected>Select school</option>
                <?php foreach ($schoolDropdownOptions as $schoolOpt): ?>
                  <?php if ($schoolOpt === 'Other') { continue; } ?>
                  <option value="<?php echo h($schoolOpt); ?>"><?php echo h($schoolOpt); ?></option>
                <?php endforeach; ?>
                <option value="Other">Other</option>
              </select>
              <p id="reg-school-error" x-show="showRegisterError('school')" x-transition.opacity class="mt-2 text-xs font-semibold text-red-600" x-text="registerError('school')"></p>
            </div>

            <div class="animate-slide-up-fade-4">
              <label for="reg-review-type" class="block text-sm font-semibold text-gray-700 mb-2">Review Type</label>
              <select id="reg-review-type" x-ref="reg_review_type" x-model="register.values.review_type" @blur="register.touched.review_type = true"
                      name="review_type" required
                      :aria-invalid="showRegisterError('review_type') ? 'true' : 'false'"
                      aria-describedby="reg-review-type-error"
                      :class="showRegisterError('review_type') ? 'border-red-400 focus:border-red-500 focus:ring-red-500/15' : ''"
                      class="w-full rounded-2xl border border-gray-300 bg-white/80 px-4 py-4 text-gray-900 shadow-sm hover:border-gray-400 hover:bg-white hover:shadow-md focus:border-accent-blue focus:ring-4 focus:ring-accent-blue/15 outline-none transition-all duration-200 appearance-none bg-[length:1.25rem] bg-[right_0.75rem_center] bg-no-repeat cursor-pointer" style="background-image:url('data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 fill=%27none%27 viewBox=%270 0 24 24%27 stroke=%27%236b7280%27%3E%3Cpath stroke-linecap=%27round%27 stroke-linejoin=%27round%27 stroke-width=%272%27 d=%27M19 9l-7 7-7-7%27/%3E%3C/svg%3E');">
                <option value="" disabled selected>Select type</option>
                <option value="reviewee">Reviewee</option>
                <option value="undergrad">Undergrad</option>
              </select>
              <p id="reg-review-type-error" x-show="showRegisterError('review_type')" x-transition.opacity class="mt-2 text-xs font-semibold text-red-600" x-text="registerError('review_type')"></p>
            </div>

            <div x-show="register.values.school === 'Other'" x-cloak class="sm:col-span-2 animate-slide-up-fade-5">
              <label for="reg-school-other" class="block text-sm font-semibold text-gray-700 mb-2">Enter School Name</label>
              <input id="reg-school-other" x-ref="reg_school_other" x-model.trim="register.values.school_other" @blur="register.touched.school_other = true"
                     type="text" name="school_other" placeholder="Your school name"
                     :aria-invalid="showRegisterError('school_other') ? 'true' : 'false'"
                     aria-describedby="reg-school-other-error"
                     :class="showRegisterError('school_other') ? 'border-red-400 focus:border-red-500 focus:ring-red-500/15' : ''"
                     class="w-full rounded-2xl border border-gray-300 bg-white/80 px-4 py-4 text-gray-900 placeholder-gray-400 shadow-sm hover:border-gray-400 hover:bg-white hover:shadow-md focus:border-accent-blue focus:ring-4 focus:ring-accent-blue/15 outline-none transition-all duration-200">
              <p id="reg-school-other-error" x-show="showRegisterError('school_other')" x-transition.opacity class="mt-2 text-xs font-semibold text-red-600" x-text="registerError('school_other')"></p>
            </div>

            <div class="sm:col-span-2 animate-slide-up-fade-6">
              <label for="reg-password" class="block text-sm font-semibold text-gray-700 mb-2">Password</label>
              <div class="relative">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"><i class="bi bi-lock text-lg"></i></span>
                <input id="reg-password" x-ref="reg_password" x-model="register.values.password" @blur="register.touched.password = true"
                       :type="showRegisterPass ? 'text' : 'password'" name="password" required minlength="6" placeholder="Min. 6 characters" autocomplete="new-password"
                       :aria-invalid="showRegisterError('password') ? 'true' : 'false'"
                       aria-describedby="reg-password-error"
                       :class="showRegisterError('password') ? 'border-red-400 focus:border-red-500 focus:ring-red-500/15' : ''"
                       class="w-full rounded-2xl border border-gray-300 bg-white/80 pl-12 pr-12 py-4 text-gray-900 placeholder-gray-400 shadow-sm hover:border-gray-400 hover:bg-white hover:shadow-md focus:border-accent-blue focus:ring-4 focus:ring-accent-blue/15 outline-none transition-all duration-200">
                <button type="button" @click="showRegisterPass = !showRegisterPass" class="absolute right-4 top-1/2 -translate-y-1/2 p-1.5 rounded-lg text-gray-400 hover:text-accent-blue hover:bg-accent-blue/10 active:scale-90 focus:outline-none focus:ring-4 focus:ring-accent-blue/15 transition-all duration-200" aria-label="Toggle password visibility">
                  <i class="bi text-xl" :class="showRegisterPass ? 'bi-eye-slash' : 'bi-eye'"></i>
                </button>
              </div>
              <p id="reg-password-error" x-show="showRegisterError('password')" x-transition.opacity class="mt-2 text-xs font-semibold text-red-600" x-text="registerError('password')"></p>
              <p class="mt-2 text-xs text-gray-500">Minimum 6 characters.</p>
            </div>
          </div>
          <div class="animate-slide-up-fade-7">
            <label for="reg-proof" class="block text-sm font-semibold text-gray-700 mb-2">Upload Payment Proof</label>
            <div class="relative">
              <input id="reg-proof" x-ref="reg_payment_proof" @change="register.values.hasProof = ($event.target.files && $event.target.files.length > 0); register.touched.payment_proof = true"
                     type="file" name="payment_proof" accept="image/*,application/pdf" required
                     :aria-invalid="showRegisterError('payment_proof') ? 'true' : 'false'"
                     aria-describedby="reg-proof-error"
                     class="block w-full text-sm text-gray-500 file:mr-4 file:py-3.5 file:px-6 file:rounded-2xl file:border-0 file:font-semibold file:bg-accent-blue/10 file:text-accent-blue file:shadow-sm file:cursor-pointer file:transition-all file:duration-200 file:hover:bg-accent-blue/20 file:hover:shadow-md file:active:scale-[0.98]">
            </div>
            <p id="reg-proof-error" x-show="showRegisterError('payment_proof')" x-transition.opacity class="mt-2 text-xs font-semibold text-red-600" x-text="registerError('payment_proof')"></p>
            <p class="text-xs text-gray-500 mt-2">
              We accept images or PDF. Max size depends on server limits.
              <span class="block mt-1">Tip: A clear screenshot/photo of your payment confirmation works great.</span>
            </p>
          </div>
          <div class="animate-slide-up-fade-8">
          <button type="submit" class="w-full py-4 px-5 rounded-2xl font-semibold text-white bg-gradient-to-r from-accent-orange to-accent-orange-light hover:from-accent-orange-dark hover:to-accent-orange focus:outline-none focus:ring-4 focus:ring-accent-orange/25 transition-all duration-200 shadow-[0_14px_28px_rgba(245,158,11,0.25)] hover:shadow-[0_18px_36px_rgba(245,158,11,0.35)] hover:scale-[1.01] active:scale-[0.99] active:translate-y-[2px] active:shadow-[0_8px_20px_rgba(245,158,11,0.3)]">
            Register
          </button>
          </div>
        </form>
        <p class="text-center mt-6 text-sm text-gray-600 animate-slide-up-fade-9">Already have an account? <a href="login.php" class="text-accent-blue font-semibold hover:text-accent-blue-dark hover:underline underline-offset-2 transition-all duration-200">Sign in</a></p>
      </div>
    </div>
  </div>
</div>
<?php if (isset($_SESSION['open_modal'])) { unset($_SESSION['open_modal']); } ?>
</body>
</html>
