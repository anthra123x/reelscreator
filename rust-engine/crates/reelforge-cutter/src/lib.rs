pub mod ffmpeg;
pub mod filtergraph;
pub mod strategies;
pub mod thumbnail;
pub mod validator;

pub use ffmpeg::VideoCutter;
pub use filtergraph::build_vertical_filtergraph;
pub use strategies::plan_cut_intervals;
pub use thumbnail::extract_midpoint_thumbnail;
pub use validator::{validate_cut_clip, ClipValidationSpec};
