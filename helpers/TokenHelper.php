<?php
class TokenHelper{
  public static function generateQRToken():string{
    $timestamp = time();
    $random    = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    return "TKT-{$timestamp}-{$random}";
    }
    
    public static function generatePaystackReference(): string {
      $timestamp = time();
      $random    = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
      return "EVT-{$timestamp}-{$random}";
  }

   /**
   * Generates a professional 8-char display ID from a real numeric id.
   * PREFIX (2) + random block + zero-padded id = 8 chars total.
   * Call this ONCE right after insert, then save the result to the row.
   */
  public static function generateDisplayId(int $id, string $prefix = 'TK'): string
  {
    $idStr    = (string) $id;
    $idLen    = max(3, strlen($idStr));
    $idPadded = str_pad($idStr, $idLen, '0', STR_PAD_LEFT);

    $randomLen = max(0, 8 - strlen($prefix) - $idLen);

    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // no 0/O, 1/I
    $random   = '';
    for ($i = 0; $i < $randomLen; $i++) {
      $random .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $prefix . $random . $idPadded;
  }
}