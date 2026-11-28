export class AuthorityError extends Error {
  constructor(code) {
    super(code);
    this.name = 'AuthorityError';
    this.code = code;
  }
}
export function requireAuthority(condition, code) {
  if (!condition) throw new AuthorityError(code);
}
