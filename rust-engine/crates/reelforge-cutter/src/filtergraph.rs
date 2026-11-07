use reelforge_core::{Dimensions, VerticalMode};

pub fn build_vertical_filtergraph(mode: VerticalMode, dimensions: Dimensions) -> String {
    let w = dimensions.width;
    let h = dimensions.height;

    match mode {
        VerticalMode::BlurPadding => {
            format!(
                "[0:v]split=2[orig][bg];\
                 [bg]scale={w}:{h}:force_original_aspect_ratio=increase,crop={w}:{h},boxblur=25:5[blurred];\
                 [orig]scale={w}:{h}:force_original_aspect_ratio=decrease[fg];\
                 [blurred][fg]overlay=(W-w)/2:(H-h)/2"
            )
        }
        VerticalMode::CenterCrop => {
            format!("[0:v]crop=ih*{w}/{h}:ih:(iw-ow)/2:0,scale={w}:{h}")
        }
        VerticalMode::Letterbox => {
            format!(
                "[0:v]scale={w}:{h}:force_original_aspect_ratio=decrease,pad={w}:{h}:(ow-iw)/2:(oh-ih)/2:black"
            )
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_blur_padding_filtergraph() {
        let fg = build_vertical_filtergraph(
            VerticalMode::BlurPadding,
            Dimensions {
                width: 1080,
                height: 1920,
            },
        );
        assert!(fg.contains("split=2[orig][bg]"));
        assert!(fg.contains("scale=1080:1920:force_original_aspect_ratio=increase"));
        assert!(fg.contains("boxblur=25:5"));
        assert!(fg.contains("overlay=(W-w)/2:(H-h)/2"));
    }

    #[test]
    fn test_center_crop_filtergraph() {
        let fg = build_vertical_filtergraph(
            VerticalMode::CenterCrop,
            Dimensions {
                width: 1080,
                height: 1920,
            },
        );
        assert!(fg.contains("crop=ih*1080/1920:ih:(iw-ow)/2:0"));
        assert!(fg.contains("scale=1080:1920"));
    }

    #[test]
    fn test_letterbox_filtergraph() {
        let fg = build_vertical_filtergraph(
            VerticalMode::Letterbox,
            Dimensions {
                width: 1080,
                height: 1920,
            },
        );
        assert!(fg.contains("pad=1080:1920:(ow-iw)/2:(oh-ih)/2:black"));
    }
}
