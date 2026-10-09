declare module '#app' {
  interface PageMeta {
    /** Page is reachable without login. */
    public?: boolean
    /** Only for admins (checked again by the API). */
    admin?: boolean
  }
}

export {}
