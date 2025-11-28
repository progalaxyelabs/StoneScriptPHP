import { ComponentFixture, TestBed } from '@angular/core/testing';

import { SelectWebsiteModalComponent } from './select-website-modal.component';

describe('SelectWebsiteModalComponent', () => {
  let component: SelectWebsiteModalComponent;
  let fixture: ComponentFixture<SelectWebsiteModalComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [SelectWebsiteModalComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(SelectWebsiteModalComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
