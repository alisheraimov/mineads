import Image from "next/image";
import Link from "next/link";

export function BrandLogo() {
  return (
    <Link href="/" aria-label="MineAds home" className="inline-flex items-center">
      <Image src="/mineads_logo.svg" alt="MineAds" width={36} height={36} priority className="dark:invert" />
    </Link>
  );
}
