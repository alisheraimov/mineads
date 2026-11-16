import Image from "next/image";
import Link from "next/link";

export function BrandLogo() {
  return (
    <Link href="/" aria-label="VoxelAds home" className="inline-flex items-center">
      <Image src="/voxelads_logo.svg" alt="VoxelAds" width={36} height={36} priority className="dark:invert" />
    </Link>
  );
}
