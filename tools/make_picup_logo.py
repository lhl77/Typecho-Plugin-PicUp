#!/usr/bin/env python3
"""生成 PicUp Logo 资源（横向锁定版： [图标] PicUp，透明底 + 亮/暗两套字色）。

源图：752x579 的深色海报（深海军蓝底 + 蓝色树叶图标 + 垂直排列的白色 PicUp 字样）。
处理：
  1. HSV 明度软阈值抠图（背景 V≈0.20，前景 V≥0.4），保留抗锯齿 alpha 并轻微羽化；
  2. 用行投影找出「图标」与「字标」之间的空白带，把两者拆开；
  3. 重新拼成横向锁定：图标在左、字标在右，字标高约图标高的 46%，间隙 16%；
  4. 导出两套：
       picup-logo-dark.png  —— 白字（暗色主题）
       picup-logo-light.png —— 深墨字 + 淡青层提对比（亮色主题）

用法：python3 tools/make_picup_logo.py [源图] [输出目录]
"""
import sys
import numpy as np
from PIL import Image, ImageFilter

SRC = sys.argv[1] if len(sys.argv) > 1 else '/tmp/picup-logo.jpg'
OUT = sys.argv[2] if len(sys.argv) > 2 else 'assets/img'

OUT_H = 132          # 导出总高（页面显示 44px，3x 便于高 DPI）
V_LOW, V_HIGH = 0.26, 0.40   # 明度软阈值：低于 LOW 全透明，高于 HIGH 全不透明
INK = (24, 28, 36)   # 亮色主题字色
WHITE_SAT, WHITE_VAL = 0.10, 0.72
TEXT_RATIO = 0.46    # 字标高 / 图标高
GAP_RATIO = 0.16     # 间隙 / 图标高
ACCENT = np.array([0x2A, 0xBC, 0xFA], dtype=np.float32) / 255.0


def alpha_from_rgb(rgb: np.ndarray) -> np.ndarray:
    v = rgb.max(axis=2)
    return np.clip((v - V_LOW) / (V_HIGH - V_LOW), 0.0, 1.0)


def trim(rgba: np.ndarray) -> np.ndarray:
    ys, xs = np.where(rgba[..., 3] > 6)
    return rgba[ys.min():ys.max() + 1, xs.min():xs.max() + 1]


def recolor(rgba: np.ndarray, light: bool) -> np.ndarray:
    arr = rgba.astype(np.float32) / 255.0
    a = arr[..., 3]
    mx = arr[..., :3].max(axis=2)
    mn = arr[..., :3].min(axis=2)
    sat = np.where(mx > 0, (mx - mn) / np.maximum(mx, 1e-6), 0)

    if light:
        ink = np.array(INK, dtype=np.float32) / 255.0
        is_white = (sat < WHITE_SAT) & (mx > WHITE_VAL) & (a > 0.15)
        arr[..., :3] = np.where(is_white[..., None], ink, arr[..., :3])
        # 图标顶部的极淡青层在白底几乎看不见 → 往主青色混 40%
        pale = (mx > 0.86) & (sat > 0.12) & (a > 0.15)
        arr[..., :3] = np.where(pale[..., None], arr[..., :3] * 0.60 + ACCENT * 0.40, arr[..., :3])

    return (np.clip(arr, 0, 1) * 255).astype(np.uint8)


def split_icon_and_text(rgba: np.ndarray):
    """按行投影找图标与字标之间的空白带。"""
    rows = (rgba[..., 3] > 6).sum(axis=1)
    active = np.where(rows > 0)[0]
    top, bottom = int(active.min()), int(active.max())
    thr = max(2, int(rows.max() * 0.02))

    y = top
    while y <= bottom:
        if rows[y] <= thr:
            j = y
            while j <= bottom and rows[j] <= thr:
                j += 1
            if j <= bottom and (j - y) >= 6:
                return top, (y, j), bottom
            y = j
        else:
            y += 1
    return None, None, None


def main() -> int:
    im = Image.open(SRC).convert('RGB')
    rgb = np.asarray(im).astype(np.float32) / 255.0
    alpha = alpha_from_rgb(rgb)
    a8 = Image.fromarray((alpha * 255).astype(np.uint8), 'L').filter(ImageFilter.GaussianBlur(0.6))
    rgba = np.dstack([np.asarray(im), np.asarray(a8)])

    top, gap, bottom = split_icon_and_text(rgba)
    if top is None:
        raise SystemExit('没能分离图标与字标，请检查源图')

    icon = trim(rgba[top:gap[0], :, :])
    text = trim(rgba[gap[1]:bottom + 1, :, :])
    print(f'icon {icon.shape[1]}x{icon.shape[0]}  text {text.shape[1]}x{text.shape[0]}')

    # ---- 横向锁定：[图标] 字标 ----
    icon_h = OUT_H
    icon_w = round(icon.shape[1] * icon_h / icon.shape[0])
    text_h = round(OUT_H * TEXT_RATIO)
    text_w = round(text.shape[1] * text_h / text.shape[0])
    gap_px = round(OUT_H * GAP_RATIO)
    total_w = icon_w + gap_px + text_w

    icon_img = Image.fromarray(icon, 'RGBA').resize((icon_w, icon_h), Image.LANCZOS)
    text_img = Image.fromarray(text, 'RGBA').resize((text_w, text_h), Image.LANCZOS)

    for name, light in (('picup-logo-light.png', True), ('picup-logo-dark.png', False)):
        canvas = Image.new('RGBA', (total_w, OUT_H), (0, 0, 0, 0))
        ic = Image.fromarray(recolor(np.asarray(icon_img), light), 'RGBA')
        tx = Image.fromarray(recolor(np.asarray(text_img), light), 'RGBA')
        canvas.paste(ic, (0, 0), ic)
        canvas.paste(tx, (icon_w + gap_px, (OUT_H - text_h) // 2), tx)
        canvas.save(f'{OUT}/{name}', optimize=True)
        print(f'  {OUT}/{name}  {total_w}x{OUT_H}')

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
