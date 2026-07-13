import { Pipe, PipeTransform } from '@angular/core';

@Pipe({
  name: 'rupiah',
  standalone: true,
})
export class RupiahPipe implements PipeTransform {
  transform(value: number | string | null | undefined, compact = false): string {
    if (value == null || value === '') return 'Rp 0';

    const num = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(num)) return 'Rp 0';

    if (compact) {
      if (num >= 1_000_000_000) return `Rp ${(num / 1_000_000_000).toFixed(1)}M`;
      if (num >= 1_000_000) return `Rp ${(num / 1_000_000).toFixed(1)}jt`;
      if (num >= 1_000) return `Rp ${(num / 1_000).toFixed(0)}rb`;
    }

    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    })
      .format(num)
      .replace('IDR', 'Rp')
      .trim();
  }
}
