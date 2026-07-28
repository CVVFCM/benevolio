# GHCR, not Docker Hub: Docker Hub names are namespace/repo and go no deeper, so
# the nested "benevolio/app" below would be rejected on push. GHCR allows it.
variable "IMAGE_PREFIX" {
    default = "ghcr.io/cvvfcm/benevolio/"
}

variable "TAGS" {
    default = "latest"
}

variable "EXTERNAL_USER_ID" {
    default = "1000"
}

group "default" {
    targets = ["app"]
}

target "app" {
    tags = [for t in split(",", TAGS) : "${IMAGE_PREFIX}app:${t}"]

    platforms = ["linux/amd64", "linux/arm64"]

    args = {
        EXTERNAL_USER_ID = EXTERNAL_USER_ID
    }

    cache-from = ["type=registry,ref=${IMAGE_PREFIX}app:cache"]
    cache-to   = ["type=registry,ref=${IMAGE_PREFIX}app:cache,mode=max"]
}
