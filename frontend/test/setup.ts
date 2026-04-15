import '@testing-library/jest-dom';

// Mock next/navigation for tests that use usePathname, useSearchParams, etc.
vi.mock('next/navigation', () => ({
  usePathname:     vi.fn(() => '/'),
  useSearchParams: vi.fn(() => new URLSearchParams()),
  useRouter:       vi.fn(() => ({
    push:    vi.fn(),
    replace: vi.fn(),
    back:    vi.fn(),
  })),
  redirect: vi.fn(),
}));

// Mock next/image so it renders a regular <img> in tests
vi.mock('next/image', () => ({
  default: ({ src, alt, ...props }: { src: string; alt: string; [k: string]: unknown }) =>
    // eslint-disable-next-line @next/next/no-img-element
    <img src={src} alt={alt} {...props} />,
}));

// Silence console.error for known React Testing Library noise
const originalError = console.error;
beforeAll(() => {
  console.error = (...args: unknown[]) => {
    if (
      typeof args[0] === 'string' &&
      (args[0].includes('Warning: ReactDOM.render') ||
       args[0].includes('act(...)'))
    ) {
      return;
    }
    originalError(...args);
  };
});
afterAll(() => {
  console.error = originalError;
});
