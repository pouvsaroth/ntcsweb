/**
 * The canonical message tree — every other locale file must match this exact
 * shape. `MessageSchema` (inferred from this) is what gives every `t('...')`
 * call compile-time key checking across the app.
 */
const en = {
  nav: {
    home: 'Home',
    about: 'About',
    programs: 'Programs',
    teachers: 'Teachers',
    students: 'Students',
    news: 'News',
    events: 'Events',
    announcements: 'Announcements',
    gallery: 'Gallery',
    documents: 'Documents',
    contact: 'Contact',
    portalLogin: 'Portal Login',
  },

  footer: {
    explore: 'Explore',
    resources: 'Resources',
    portal: 'Portal',
    login: 'Login',
    rightsReserved: 'All rights reserved.',
  },

  common: {
    readMore: 'Read more →',
    loading: 'Loading…',
    noRecordsFound: 'No records found.',
    searchPlaceholder: 'Search…',
    showingResults: 'Showing {from}–{to} of {total}',
    previousPage: 'Previous page',
    nextPage: 'Next page',
    signOut: 'Sign out',
    close: 'Close',
    language: 'Language',
    primaryNav: 'Primary',
    toggleMenu: 'Toggle menu',
    toggleSidebar: 'Toggle sidebar',
    pagination: 'Pagination',
    retry: 'Retry',
    save: 'Save',
  },

  auth: {
    school: 'School',
    schoolHint: 'Choose your school so we know which account to check.',
    schoolPlaceholder: 'Select your school',
    schoolLoadError: "Couldn't load the list of schools.",
    login: {
      title: 'Sign in',
      subtitle: 'Access the admin portal',
      identifier: 'Email or phone',
      password: 'Password',
      rememberMe: 'Remember me',
      forgotPassword: 'Forgot password?',
      submit: 'Sign in',
      signUpPrompt: "Don't have an account?",
      signUp: 'Sign up',
      genericError: 'Something went wrong. Please try again.',
    },
    forgotPassword: {
      title: 'Forgot password',
      subtitle: "We'll email you a link to reset it",
      email: 'Email address',
      submit: 'Send reset link',
      sent: 'If an account matches that email address, a password reset link has been sent.',
      backToSignIn: '← Back to sign in',
    },
    resetPassword: {
      title: 'Reset password',
      subtitle: 'Choose a new password for your account',
      newPassword: 'New password',
      confirmPassword: 'Confirm new password',
      submit: 'Reset password',
    },
  },

  home: {
    heroTitle: 'Welcome to {name}',
    heroSubtitle:
      'Empowering students with the knowledge and skills to thrive — quality education, dedicated teachers, and a community built on excellence.',
    exploreProgramsCta: 'Explore Programs',
    contactUsCta: 'Contact Us',
    learnMore: 'Learn More',
    latestNews: 'Latest News',
    latestNewsSubtitle: "Stay up to date with what's happening at our school",
    noNewsTitle: 'No news posted yet',
    noNewsMessage: 'Announcements and school news will appear here once published.',
    upcomingEvents: 'Upcoming Events',
    upcomingEventsSubtitle: 'Join us at these upcoming activities',
    noEventsTitle: 'No upcoming events',
    noEventsMessage: 'Check back soon for school events and activities.',
  },

  about: {
    title: 'About Us',
    subtitle: 'Our mission, values, and history',
    body: '{name} is committed to providing quality education that prepares students for success. Detailed school history, mission, and values content will be managed by the school administrator and published here.',
  },

  programs: {
    title: 'Programs',
    subtitle: 'Academic programs we offer',
    emptyTitle: 'Programs coming soon',
    emptyMessage: 'Our academic programs will be listed here once published by the school.',
  },

  teachers: {
    title: 'Our Teachers',
    subtitle: 'Meet our dedicated teaching staff',
    emptyTitle: 'Teacher profiles coming soon',
  },

  students: {
    title: 'Student Life',
    subtitle: 'Life and achievements of our students',
    emptyTitle: 'Student life content coming soon',
    emptyMessage: 'Student achievements, activities, and testimonials will be published here.',
  },

  news: {
    title: 'News',
    subtitle: 'Latest updates and announcements',
    emptyTitle: 'No news posted yet',
    notFoundTitle: 'Article not found',
    notFoundMessage: 'This news article may have been removed or is no longer available.',
  },

  events: {
    title: 'Events',
    subtitle: 'Upcoming school events and activities',
    emptyTitle: 'No upcoming events',
  },

  announcements: {
    title: 'Announcements',
    subtitle: 'Important notices from the school',
    emptyTitle: 'No announcements yet',
    emptyMessage: 'Important school announcements will be posted here.',
  },

  gallery: {
    title: 'Gallery',
    subtitle: 'Photos from school life and events',
    emptyTitle: 'No photos yet',
    emptyMessage: 'Gallery photos will appear here once uploaded.',
  },

  documents: {
    title: 'Documents',
    subtitle: 'Forms and downloadable resources',
    emptyTitle: 'No documents published yet',
    emptyMessage: 'Downloadable forms and resources will be listed here.',
  },

  contact: {
    title: 'Contact Us',
    subtitle: 'Get in touch with our school',
    schoolInfo: 'School Information',
    address: 'Address',
    phone: 'Phone',
    email: 'Email',
    formName: 'Name',
    formEmail: 'Email',
    formSubject: 'Subject',
    formMessage: 'Message',
    submit: 'Send Message',
    successMessage: "Thank you — your message has been sent. We'll get back to you soon.",
    errorMessage: "Sorry, we couldn't send your message right now. Please try again later.",
  },

  notFound: {
    title: 'Page not found',
    message: "The page you're looking for doesn't exist.",
    backHome: 'Back to Home',
  },

  admin: {
    dashboard: {
      welcomeBack: 'Welcome back, {name}',
      platformAdministration: 'Platform administration',
      statStudents: 'Students',
      statTeachers: 'Teachers',
      statUsers: 'Users',
      statPrograms: 'Active Programs',
      statHintStudents: 'Available once Student Management ships',
      statHintTeachers: 'Available once Teacher Management ships',
      statHintUsers: 'Available once the Admin API ships',
      statHintPrograms: 'Available once Academic Management ships',
    },
    users: {
      title: 'Users',
      searchPlaceholder: 'Search by name or email…',
      columnName: 'Name',
      columnEmail: 'Email',
      columnStatus: 'Status',
      columnRoles: 'Roles',
      columnJoined: 'Joined',
      emptyMessage: 'No users found.',
      apiNotReady: 'this is expected until the Admin API (Phase 6) adds GET /api/v1/admin/users.',
    },
    comingSoon: {
      notBuiltYet: '{title} is not built yet',
      message:
        'This section is wired up in the navigation and ready to go — the backend endpoints for it land in a later phase.',
    },
    homeSlides: {
      title: 'Homepage Slider',
      pageSubtitle: 'Manage the images shown in your public homepage slider.',
      addSlide: 'Add Slide',
      columnPreview: 'Preview',
      columnTitle: 'Title',
      columnOrder: 'Order',
      columnStatus: 'Status',
      columnActions: 'Actions',
      edit: 'Edit',
      delete: 'Delete',
      deleteConfirm: 'Delete this slide? This cannot be undone.',
      emptyMessage: 'No slides yet. Add one to show it on your homepage.',
      createTitle: 'Add homepage slide',
      editTitle: 'Edit homepage slide',
      image: 'Image',
      imageRequired: 'Please choose an image.',
      imageHint: 'JPEG, PNG, WebP, or GIF — up to 10MB.',
      slideTitle: 'Title',
      subtitle: 'Subtitle',
      linkUrl: 'Link URL',
      linkUrlHint: 'Optional — where visitors go if they click this slide.',
      sortOrder: 'Order',
      status: 'Status',
      statusActive: 'Active',
      statusInactive: 'Inactive',
    },
  },

  adminNav: {
    groups: {
      overview: 'Overview',
      platform: 'Platform',
      school: 'School',
      usersAccess: 'Users & Access',
      academic: 'Academic',
      students: 'Students',
      teachers: 'Teachers',
      academicRecords: 'Academic Records',
      website: 'Website',
      communication: 'Communication',
      system: 'System',
    },
    items: {
      dashboard: 'Dashboard',
      tenants: 'Tenants',
      settings: 'Settings',
      users: 'Users',
      roles: 'Roles',
      academicYears: 'Academic Years',
      programs: 'Programs',
      subjects: 'Subjects',
      classes: 'Classes',
      studentsList: 'Students',
      enrollments: 'Enrollments',
      teachersList: 'Teachers',
      attendance: 'Attendance',
      exams: 'Exams',
      grades: 'Grades',
      homeSlides: 'Homepage Slider',
      news: 'News',
      events: 'Events',
      announcements: 'Announcements',
      gallery: 'Gallery',
      documents: 'Documents',
      contactMessages: 'Contact Messages',
      notifications: 'Notifications',
      auditLogs: 'Audit Logs',
    },
  },
} as const

export default en

/**
 * `typeof en` alone would make every leaf a string *literal* type (thanks to
 * `as const`), forcing km/zh/ko/ja to contain the exact English text. This
 * keeps the key structure (so a typo in a key, or a missing key in another
 * locale, is still a compile error) while widening every leaf to `string`.
 */
type Widen<T> = T extends string ? string : { [K in keyof T]: Widen<T[K]> }

export type MessageSchema = Widen<typeof en>
